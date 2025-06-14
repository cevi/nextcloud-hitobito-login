<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Migration;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ILogger;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

class Uninstall implements IRepairStep {

    public function __construct(
        private LoggerInterface $logger,
        private IAppConfig $appConfig,
        private IConfig $config,
    ) {}

    public function getName(): string {
        return 'Uninstall '.Application::APP_ID;
    }

    public function run(IOutput $output): void
    {
		$output->info('Uninstalling '.Application::APP_ID.'...');

        $output->info("This step will take 10 seconds.");
        $output->startProgress(10);
        for ($i = 0; $i < 10; $i++) {
            sleep(1);
            $output->advance(1);
        }
        $output->finishProgress();

        $output->warning('HitobitoLogin to be disabled.');
        $this->logger->error('Uninstalling hitobitoLogin');

		$output->info(Application::APP_ID.' was successfully uninstalled.');
    }
}
