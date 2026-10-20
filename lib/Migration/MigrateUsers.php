<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Migration;

use OCA\HitobitoLogin\AppInfo\Application;
use OCA\HitobitoLogin\Service\ProvisioningService;
use OCP\Config\IUserConfig;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Migrates all users who logged in through this app upon app update.
 * Users failing to migrate are migrated upon their next login instead.
 */
class MigrateUsers implements IRepairStep {
	public function __construct(
		private LoggerInterface $logger,
		private IUserConfig $userConfig,
		private IUserManager $userManager,
		private ProvisioningService $provisioningService,
	) {
	}

	public function getName(): string {
		return 'Migrate users who logged in with hitobito';
	}

	public function run(IOutput $output): void {
		$userIds = array_keys($this->userConfig->getValuesByUsers(Application::APP_ID, 'hitobito_id'));
		$failedCount = 0;

		$output->startProgress(count($userIds));

		foreach ($userIds as $userId) {
			// Numeric user IDs become int array keys
			/** @psalm-suppress RedundantCastGivenDocblockType */
			$userId = (string)$userId;

			$user = $this->userManager->get($userId);
			if ($user !== null) {
				try {
					$this->provisioningService->migrateUser($user);
				} catch (\Throwable $e) {
					$failedCount++;
					$this->logger->error(
						self::class . ' - failed to migrate {user}',
						[
							'app' => Application::APP_ID,
							'user' => $userId,
							'exception' => $e,
						]
					);
				}
			}
			sleep(5);

			$output->advance();
		}

		$output->finishProgress();

		if ($failedCount > 0) {
			$output->warning("$failedCount users could not be migrated, they are migrated upon their next login instead. See the log for details.");
		}
	}
}
