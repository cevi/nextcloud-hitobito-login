<?php

namespace OCA\HitobitoLogin\Service;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\Accounts\IAccountManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ProvisioningService {
	public function __construct(
		private LoggerInterface $logger,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IAccountManager $accountManager,
	) {

	}

	public function provisionUser(object $profileData, array $mappedGroupIDs, ?IUser $existingUser = null): ?IUser {
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

		$this->provisionUserGroups($user, $mappedGroupIDs);

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

	private function checkMapping(object $profileData, string $targetGroup, string $targetRole): bool {
		if (!is_array($profileData->roles)) {
			return false;
		}

		foreach ($profileData->roles as $roleData) {
			if ($this->checkGroup($roleData->group_id, $targetGroup) && $this->checkRole($roleData->role_class, $targetRole)) {
				return true;
			}
		}

		return false;
	}

	public function getMappedGroups(object $profileData): array {
		$groupMappings = $this->appConfig->getValueArray(Application::APP_ID, 'group_mappings');
		$mappedGroups = [];

		foreach ($groupMappings as $groupMapping) {
			$group = $groupMapping['group'];
			$role = $groupMapping['role'];
			$targets = $groupMapping['targets'];

			if ($this->checkMapping($profileData, $group, $role)) {
				$mappedGroups = array_merge($mappedGroups, $targets);
			}
		}

		return array_unique($mappedGroups);
	}

	private function provisionUserGroups(IUser $user, array $mappedGroupIDs) {
		$currentGroups = $this->groupManager->getUserGroups($user);
		$currentGroupIDs = array_map(function (IGroup $group) {
			return $group->getGID();
		}, $currentGroups);

		$generalSettings = $this->config->getSystemValue(Application::APP_ID);
		if (!is_array($generalSettings['options'])) {
			$this->logger->error('No options found in general settings');
			return;
		}

		$newGroups = array_diff($mappedGroupIDs, $currentGroupIDs);
		$removedGroups = array_diff($currentGroupIDs, $mappedGroupIDs);

		foreach ($newGroups as $newGroupGID) {
			$newGroup = $this->groupManager->get($newGroupGID);
			if (!$newGroup) {
				$this->logger->error('Group not found', ['group' => $newGroupGID]);
				continue;
			}

			$newGroup->addUser($user);
		}

		if (in_array('prune_groups', $generalSettings['options'])) {
			foreach ($removedGroups as $removedGroupGID) {
				$removedGroup = $this->groupManager->get($removedGroupGID);
				if (!$removedGroup) {
					$this->logger->error('Group not found', ['group' => $removedGroupGID]);
					continue;
				}

				$removedGroup->removeUser($user);
			}
		}
	}
}
