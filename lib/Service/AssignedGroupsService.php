<?php

namespace OCA\HitobitoLogin\Service;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Config\ValueType;

class AssignedGroupsService {
	private const USER_VALUE_KEY = 'assigned_groups';

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	/**
	 * @return string[]
	 */
	public function getAssignedGroupIDs(string $userId): array {
		/** @var string[] $groupIDs */
		$groupIDs = $this->userConfig->getValueArray($userId, Application::APP_ID, self::USER_VALUE_KEY);

		return $groupIDs;
	}

	/**
	 * @param string[] $groupIDs
	 */
	public function setAssignedGroupIDs(string $userId, array $groupIDs): void {
		$groupIDs = array_values(array_unique($groupIDs));

		if (count($groupIDs) === 0) {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::USER_VALUE_KEY);
			return;
		}

		$this->userConfig->setValueArray($userId, Application::APP_ID, self::USER_VALUE_KEY, $groupIDs);
	}

	public function removeAssignedGroup(string $userId, string $groupID): void {
		$groupIDs = $this->getAssignedGroupIDs($userId);
		if (!in_array($groupID, $groupIDs, true)) {
			return;
		}

		$this->setAssignedGroupIDs($userId, array_diff($groupIDs, [$groupID]));
	}

	public function removeAssignedGroupFromAllUsers(string $groupID): void {
		$values = $this->userConfig->getValuesByUsers(Application::APP_ID, self::USER_VALUE_KEY, ValueType::ARRAY);

		foreach ($values as $userId => $groupIDs) {
			if (is_array($groupIDs) && in_array($groupID, $groupIDs, true)) {
				// Numeric user IDs become int array keys
				/** @psalm-suppress RedundantCastGivenDocblockType */
				$this->removeAssignedGroup((string)$userId, $groupID);
			}
		}
	}
}
