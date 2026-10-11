<?php

namespace OCA\HitobitoLogin\Service;

use OCA\HitobitoLogin\AppInfo\Application;
use OCA\HitobitoLogin\Group\HitobitoGroupBackend;
use OCP\Accounts\IAccountManager;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\GroupInterface;
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
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private HitobitoGroupBackend $groupBackend,
		private IAccountManager $accountManager,
		private IEventDispatcher $dispatcher,
		private IAppManager $appManager,
		private SettingsService $settingsService,
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

			$this->config->setUserValue($user->getUID(), Application::APP_ID, 'app_version', $this->appVersion);
		}

		$this->config->setUserValue($user->getUID(), Application::APP_ID, 'hitobito_id', $profileData->id);

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

	/**
	 * Synchronizes the app-assigned group memberships of the user with the mapped groups.
	 *
	 * Memberships are written to the app's own group backend, so only memberships that were assigned by this app
	 * are ever removed again. Memberships the user has through other backends (assigned by an admin, LDAP, ...)
	 * are never touched, and no app-assigned membership is created for a group the user is already in otherwise.
	 *
	 * @param list<string> $mappedGroupIDs
	 */
	public function provisionUserGroups(IUser $user, array $mappedGroupIDs): void {
		$mappedGroupIDs = array_values(array_unique(array_map('strval', $mappedGroupIDs)));

		// Memberships through any backend
		$currentGroupIDs = array_unique($this->groupManager->getUserGroupIds($user));
		// Memberships assigned by this app
		$ownGroupIDs = $this->groupBackend->getUserGroups($user->getUID());

		foreach (array_diff($mappedGroupIDs, $currentGroupIDs) as $gid) {
			$this->addMembership($user, $gid);
		}

		if ($this->settingsService->hasGeneralOption(SettingsService::GENERAL_OPTION_PRUNE_GROUPS)) {
			foreach (array_diff($ownGroupIDs, $mappedGroupIDs) as $gid) {
				$this->removeMembership($user, $gid);
			}
		}
	}

	private function addMembership(IUser $user, string $gid): void {
		// The group must exist in another backend, this app never creates groups. Do not resolve it through
		// IGroupManager::get() before the membership is written: the group manager caches the group object together
		// with the backends that know the group at that moment, and this app's backend would be missing from it
		// for the rest of the request.
		if (!$this->groupExistsInOtherBackend($gid)) {
			$this->logger->error('Group not found', ['group' => $gid]);
			return;
		}

		$this->groupBackend->addMembership($user->getUID(), $gid);

		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return;
		}

		$this->clearGroupManagerCache('AddUser', $group, $user);
		$this->dispatcher->dispatchTyped(new UserAddedEvent($group, $user));
		$this->logger->info(
			self::class . ' - {user} added to {group}',
			[
				'app' => Application::APP_ID,
				'user' => $user->getUID(),
				'group' => $gid
			]
		);
	}

	private function removeMembership(IUser $user, string $gid): void {
		$group = $this->groupManager->get($gid);

		$this->groupBackend->removeFromGroup($user->getUID(), $gid);
		$this->logger->info(
			self::class . ' - {user} removed from {group}',
			[
				'app' => Application::APP_ID,
				'user' => $user->getUID(),
				'group' => $gid
			]
		);

		if ($group === null) {
			// The group does not exist anymore, only the stale membership was cleaned up
			return;
		}

		$this->clearGroupManagerCache('RemoveUser', $group, $user);

		// The user may still be a member through another backend, in which case nothing changed for them
		if (!$this->isInGroupViaOtherBackend($user->getUID(), $gid)) {
			$this->dispatcher->dispatchTyped(new UserRemovedEvent($group, $user));
		}
	}

	/**
	 * @return list<GroupInterface> all group backends except the one of this app
	 */
	private function getOtherBackends(): array {
		return array_values(array_filter(
			$this->groupManager->getBackends(),
			fn (GroupInterface $backend): bool => !$backend instanceof HitobitoGroupBackend
		));
	}

	private function groupExistsInOtherBackend(string $gid): bool {
		foreach ($this->getOtherBackends() as $backend) {
			if ($backend->groupExists($gid)) {
				return true;
			}
		}

		return false;
	}

	private function isInGroupViaOtherBackend(string $uid, string $gid): bool {
		foreach ($this->getOtherBackends() as $backend) {
			if ($backend->inGroup($uid, $gid)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The group manager caches the groups of a user for the duration of the request and only drops that cache
	 * through the legacy hooks emitted by IGroup::addUser() and IGroup::removeUser() (which hooks exactly differs
	 * between Nextcloud versions, so both the pre and the post hook are emitted). As memberships are written to
	 * the app's backend directly, emit the same hooks here. Otherwise listeners of the UserAddedEvent, e.g. the
	 * automatic acceptance of group shares, would still see the old group list of the user.
	 *
	 * @param 'AddUser'|'RemoveUser' $action
	 * @psalm-suppress UndefinedClass
	 * @psalm-suppress UndefinedInterfaceMethod
	 */
	private function clearGroupManagerCache(string $action, IGroup $group, IUser $user): void {
		if ($this->groupManager instanceof \OC\Hooks\PublicEmitter) {
			$this->groupManager->emit('\OC\Group', 'pre' . $action, [$group, $user]);
			$this->groupManager->emit('\OC\Group', 'post' . $action, [$group, $user]);
		}
	}

	/**
	 * @param list<string> $mappedGroupIDs
	 */
	public function migrateUser(IUser $user, array $mappedGroupIDs): void {
		$userAppVersion = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'app_version', '0.0.0');

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

			// Users provisioned before 1.1.1 did not get the UserAddedEvent, so group shares were not set up for
			// them. Dispatch it now for their mapped groups without changing any membership.
			foreach ($mappedGroupIDs as $groupID) {
				$group = $this->groupManager->get($groupID);
				if ($group !== null && $group->inGroup($user)) {
					$this->dispatcher->dispatchTyped(new UserAddedEvent($group, $user));
				}
			}
		}

		$this->config->setUserValue($user->getUID(), Application::APP_ID, 'app_version', $this->appVersion);
	}
}
