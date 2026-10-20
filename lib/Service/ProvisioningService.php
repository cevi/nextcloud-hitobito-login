<?php

namespace OCA\HitobitoLogin\Service;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\Accounts\IAccountManager;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ProvisioningService {
	private string $appVersion;

	public function __construct(
		private LoggerInterface $logger,
		private IConfig $config,
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IAccountManager $accountManager,
		private IEventDispatcher $dispatcher,
		private IAppManager $appManager,
		private AssignedGroupsService $assignedGroupsService,
	) {
		$this->appVersion = $this->appManager->getAppVersion(Application::APP_ID);
	}

	public function provisionUser(object $profileData, ?IUser $existingUser = null): ?IUser {
		$user = $existingUser;
		if (!$user) {
			$userId = "hitobito_$profileData->id";
			$userPassword = hash_hkdf('sha256', random_bytes(16), 64, 'hitobito', random_bytes(16));

			$this->logger->info('Creating new user', ['userId' => $userId]);

			$user = $this->userManager->createUser($userId, $userPassword);
			if (!$user) {
				return null;
			}
		}

		// Users logging in through this app for the first time (new or found by email) have nothing to migrate
		if (!$this->userConfig->hasKey($user->getUID(), Application::APP_ID, 'hitobito_id')) {
			$this->userConfig->setValueString($user->getUID(), Application::APP_ID, 'app_version', $this->appVersion);
		}

		$this->userConfig->setValueString($user->getUID(), Application::APP_ID, 'hitobito_id', (string)$profileData->id);

		$user->setSystemEMailAddress($profileData->email);
		if ($profileData->nickname) {
			$user->setDisplayName($profileData->nickname);
		} elseif ($profileData->first_name || $profileData->last_name) {
			$user->setDisplayName($profileData->first_name . ' ' . $profileData->last_name);
		}

		$account = $this->accountManager->getAccount($user);

		if ($profileData->town) {
			$addressProperty = $account->getProperty(IAccountManager::PROPERTY_ADDRESS);
			$addressProperty->setValue($profileData->town);
		}
		if ($profileData->birthday) {
			$birthdateProperty = $account->getProperty(IAccountManager::PROPERTY_BIRTHDATE);
			$birthdateProperty->setValue($profileData->birthday);
		}

		$this->accountManager->updateAccount($account);

		return $user;
	}

	private function checkGroup(int $groupId, string $targetGroup) {
		$groupId = strval($groupId);
		$targetGroup = strtolower($targetGroup);

		if ($targetGroup === '*') {
			return true;
		}

		if ($groupId === $targetGroup) {
			return true;
		}

		return false;
	}

	private function checkRole(string $role, string $targetRole): bool {
		$role = strtolower($role);
		$targetRole = strtolower($targetRole);

		if ($targetRole === '*') {
			return true;
		}

		$splitTargetRole = explode('*', $targetRole);
		if (
			count($splitTargetRole) === 2 &&
			str_starts_with($role, $splitTargetRole[0]) &&
			str_ends_with($role, $splitTargetRole[1])
		) {
			return true;
		}

		if ($role === $targetRole) {
			return true;
		}

		return false;
	}

	private function checkRolesMapping(object $profileData, string $targetGroup, string $targetRole): bool {
		if (!is_array($profileData->roles)) {
			return false;
		}

		foreach ($profileData->roles as $roleData) {
			if ($this->checkGroup($roleData->group_id, $targetGroup) && $this->checkRole($roleData->role_class,
					$targetRole)) {
				return true;
			}
		}

		return false;
	}

	private function checkEventMapping(array $eventRoleMap, string $targetEvent, string $targetRole): bool {
		if ($targetEvent === '*') {
			foreach ($eventRoleMap as $eventRoles) {
				foreach ($eventRoles as $role) {
					if ($this->checkRole($role, $targetRole)) {
						return true;
					}
				}
			}

			return false;
		}

		// Prefix event ID with "e" to workaround problem with array_merge_recursive with number keys
		$targetEventPrefixed = "e" . $targetEvent;

		if (!isset($eventRoleMap[$targetEventPrefixed])) {
			return false;
		}

		foreach ($eventRoleMap[$targetEventPrefixed] as $role) {
			if ($this->checkRole($role, $targetRole)) {
				return true;
			}
		}

		return false;
	}

	public function parseEventParticipationsData(object $eventParticipationsData): array {
		if (!is_array($eventParticipationsData->data) || !is_array($eventParticipationsData->included)) {
			return [];
		}

		$participationTypeMap = [];
		foreach ($eventParticipationsData->included as $included) {
			if ($included->type !== 'event_roles' || !isset($included->attributes->type)) {
				continue;
			}

			$participationTypeMap[$included->id] = $included->attributes->type;
		}

		$eventRoleMap = [];

		foreach ($eventParticipationsData->data as $data) {
			if (!isset($data->attributes->event_id) || !is_array($data->relationships->roles->data)) {
				continue;
			}

			// Prefix event ID with "e" to workaround problem with array_merge_recursive with number keys
			$eventId = "e" . $data->attributes->event_id;

			foreach ($data->relationships->roles->data as $roleData) {
				if ($roleData->type !== 'event_roles' || !isset($participationTypeMap[$roleData->id])) {
					continue;
				}

				if (!isset($eventRoleMap[$eventId])) {
					$eventRoleMap[$eventId] = [];
				}

				$eventRoleMap[$eventId][] = $participationTypeMap[$roleData->id];
			}
		}

		return $eventRoleMap;
	}

	public function getMappedGroupsFromProfileData(object $profileData): array {
		$groupMappings = $this->appConfig->getValueArray(Application::APP_ID, 'group_mappings');
		$mappedGroups = [];

		foreach ($groupMappings as $groupMapping) {
			$group = $groupMapping['group'];
			$role = $groupMapping['role'];
			$targets = $groupMapping['targets'];

			if ($this->checkRolesMapping($profileData, $group, $role)) {
				$mappedGroups = array_merge($mappedGroups, $targets);
			}
		}

		return array_unique($mappedGroups);
	}

	public function getMappedGroupsFromEventRoleMap(array $eventRoleMap): array {
		$eventMappings = $this->appConfig->getValueArray(Application::APP_ID, 'event_mappings');
		$mappedGroups = [];

		foreach ($eventMappings as $eventMapping) {
			$event = $eventMapping['event'];
			$role = $eventMapping['role'];
			$targets = $eventMapping['targets'];

			if ($this->checkEventMapping($eventRoleMap, $event, $role)) {
				$mappedGroups = array_merge($mappedGroups, $targets);
			}
		}

		return array_unique($mappedGroups);
	}

	protected function addUserToGroup(IUser $user, string $newGroupID): bool {
		$newGroup = $this->groupManager->get($newGroupID);
		if (!$newGroup) {
			$this->logger->error('Group not found', ['group' => $newGroupID]);
			return false;
		}

		$newGroup->addUser($user);

		$this->dispatcher->dispatchTyped(new UserAddedEvent($newGroup, $user));
		$this->logger->info(
			self::class . ' - {user} added to {group}',
			[
				'app' => Application::APP_ID,
				'user' => $user->getUID(),
				'group' => $newGroupID
			]
		);

		return true;
	}

	protected function removeUserFromGroup(IUser $user, string $removedGroupID): void {
		$removedGroup = $this->groupManager->get($removedGroupID);
		if (!$removedGroup) {
			$this->logger->error('Group not found', ['group' => $removedGroupID]);
			return;
		}

		$removedGroup->removeUser($user);

		$this->dispatcher->dispatchTyped(new UserRemovedEvent($removedGroup, $user));
		$this->logger->info(
			self::class . ' - {user} removed from {group}',
			[
				'app' => Application::APP_ID,
				'user' => $user->getUID(),
				'group' => $removedGroupID
			]
		);
	}

	/**
	 * @param string[] $mappedGroupIDs
	 */
	public function provisionUserGroups(IUser $user, array $mappedGroupIDs) {
		$currentGroups = $this->groupManager->getUserGroups($user);
		$currentGroupIDs = array_map(function(IGroup $group) {
			return $group->getGID();
		}, $currentGroups);

		$generalSettings = $this->config->getSystemValue(Application::APP_ID);
		if (!is_array($generalSettings['options'])) {
			$this->logger->error('No options found in general settings');
			return;
		}

		// Only memberships the user still has are relevant, others might have been removed while the app was disabled
		$assignedGroupIDs = array_intersect(
			$this->assignedGroupsService->getAssignedGroupIDs($user->getUID()),
			$currentGroupIDs
		);

		// Groups the user is already a member of by other means are not tracked, so they are never removed by this app
		$newGroups = array_diff($mappedGroupIDs, $currentGroupIDs);

		foreach ($newGroups as $newGroupID) {
			if ($this->addUserToGroup($user, $newGroupID)) {
				$assignedGroupIDs[] = $newGroupID;
			}
		}

		$removedGroups = array_diff($assignedGroupIDs, $mappedGroupIDs);

		foreach ($removedGroups as $removedGroupID) {
			$this->removeUserFromGroup($user, $removedGroupID);
		}

		$assignedGroupIDs = array_diff($assignedGroupIDs, $removedGroups);

		$this->assignedGroupsService->setAssignedGroupIDs($user->getUID(), $assignedGroupIDs);
	}

	/**
	 * Needs to run before provisionUserGroups, so the groups are provisioned based on the migrated state
	 */
	public function migrateUser(IUser $user) {
		$userAppVersion = $this->userConfig->getValueString($user->getUID(), Application::APP_ID, 'app_version', '0.0.0');

		if ($userAppVersion === $this->appVersion) {
			return;
		}

		if (version_compare($userAppVersion, '1.1.1', '<')) {
			$this->logger->info(
				self::class . ' - migrating {user} from {userAppVersion} to 1.1.1',
				[
					'app' => Application::APP_ID,
					'user' => $user->getUID(),
					'userAppVersion' => $userAppVersion
				]
			);

			// Older versions added users to groups without executing the necessary hooks.
			foreach ($this->groupManager->getUserGroups($user) as $group) {
				if (!$group->canRemoveUser() || !$group->canAddUser()) {
					continue;
				}

				try {
					$this->removeUserFromGroup($user, $group->getGID());
				} finally {
					$this->addUserToGroup($user, $group->getGID());
				}
			}
		}

		if (version_compare($userAppVersion, '1.3.0', '<')) {
			$this->logger->info(
				self::class . ' - migrating {user} from {userAppVersion} to 1.3.0',
				[
					'app' => Application::APP_ID,
					'user' => $user->getUID(),
					'userAppVersion' => $userAppVersion
				]
			);

			// Previously not tracked, adding all groups whose memberships could have been assigned by this app
			$groupIDs = [];
			foreach ($this->groupManager->getUserGroups($user) as $group) {
				if ($group->canRemoveUser() && $group->canAddUser()) {
					$groupIDs[] = $group->getGID();
				}
			}

			$this->assignedGroupsService->setAssignedGroupIDs($user->getUID(), $groupIDs);
		}

		$this->userConfig->setValueString($user->getUID(), Application::APP_ID, 'app_version', $this->appVersion);
	}
}
