<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Migration;

use OCA\HitobitoLogin\AppInfo\Application;
use OCA\HitobitoLogin\Group\HitobitoGroupBackend;
use OCA\HitobitoLogin\Service\SettingsService;
use OCP\Group\Backend\INamedBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCP\GroupInterface;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Moves group memberships that this app assigned before it had its own group backend into that backend.
 *
 * Up to version 1.2.x memberships were written to the core database backend, so there is no record of which
 * memberships were assigned by the app. The "prune groups" option tells us what the admin intended:
 * - If it was enabled, every membership of a hitobito user was effectively managed by the app (anything else
 *   would have been removed on the user's next login), so all of them are moved.
 * - If it was disabled, the app never removed anything, and it keeps that promise: existing memberships stay in
 *   the core backend and are never removed by the app.
 *
 * The move runs once (guarded by an app config flag). Additionally, every run removes app-assigned memberships of
 * groups that no longer exist in any other backend.
 *
 * @psalm-suppress UnusedClass
 */
class MigrateGroupMembershipsToBackend implements IRepairStep {
	public const MIGRATED_FLAG = 'group_backend_migrated';

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private HitobitoGroupBackend $groupBackend,
		private SettingsService $settingsService,
	) {
	}

	public function getName(): string {
		return 'Move group memberships assigned by Hitobito Login into its own group backend';
	}

	public function run(IOutput $output): void {
		$this->removeOrphanedGroups($output);

		if ($this->appConfig->getValueBool(Application::APP_ID, self::MIGRATED_FLAG)) {
			return;
		}

		if (!$this->settingsService->hasGeneralOption(SettingsService::GENERAL_OPTION_PRUNE_GROUPS)) {
			$output->info('Hitobito Login: group pruning is disabled, existing group memberships stay as they are.');
			$this->appConfig->setValueBool(Application::APP_ID, self::MIGRATED_FLAG, true);
			return;
		}

		$databaseBackend = $this->findDatabaseBackend();
		if ($databaseBackend === null) {
			$output->warning('Hitobito Login: core database group backend not found, group memberships were not migrated.');
			return;
		}

		$userIds = $this->getHitobitoUserIds();
		$moved = 0;

		$output->startProgress(count($userIds));
		foreach ($userIds as $uid) {
			foreach ($databaseBackend->getUserGroups($uid) as $gid) {
				$this->groupBackend->addMembership($uid, $gid);
				$databaseBackend->removeFromGroup($uid, $gid);
				$moved++;
			}
			$output->advance();
		}
		$output->finishProgress();

		$output->info(sprintf(
			'Hitobito Login: moved %d group memberships of %d hitobito users into the hitobito group backend.',
			$moved,
			count($userIds)
		));

		$this->appConfig->setValueBool(Application::APP_ID, self::MIGRATED_FLAG, true);
	}

	private function removeOrphanedGroups(IOutput $output): void {
		foreach ($this->groupBackend->getAllGroupIds() as $gid) {
			foreach ($this->groupManager->getBackends() as $backend) {
				if (!$backend instanceof HitobitoGroupBackend && $backend->groupExists($gid)) {
					continue 2;
				}
			}

			$this->groupBackend->deleteGroup($gid);
			$output->info("Hitobito Login: removed memberships of group '$gid', which no longer exists.");
		}
	}

	/**
	 * @return (GroupInterface&IRemoveFromGroupBackend)|null
	 */
	private function findDatabaseBackend(): ?GroupInterface {
		foreach ($this->groupManager->getBackends() as $backend) {
			if (
				$backend instanceof INamedBackend
				&& $backend instanceof IRemoveFromGroupBackend
				&& $backend->getBackendName() === 'Database'
			) {
				return $backend;
			}
		}

		return null;
	}

	/**
	 * @return list<string> ids of all users that have logged in through this app
	 */
	private function getHitobitoUserIds(): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->selectDistinct('userid')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('hitobito_id')))
			->executeQuery();

		$userIds = [];
		while (($row = $result->fetch()) !== false) {
			$userIds[] = (string)$row['userid'];
		}
		$result->closeCursor();

		return $userIds;
	}
}
