<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\AppInfo;

use OCA\HitobitoLogin\AlternativeLogin\HitobitoLogin;
use OCA\HitobitoLogin\Service\SettingsService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hitobitologin';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		/** @var SettingsService $settingsService */
		$settingsService = $this->getContainer()->get(SettingsService::class);

		if ($settingsService->isAppSetup()) {
			$context->registerAlternativeLogin(HitobitoLogin::class);
		} else {
			/** @var LoggerInterface $logger */
			$logger = $this->getContainer()->get(LoggerInterface::class);
			$logger->warning('HitobitoLogin app is not set up yet. Please configure the app in the admin settings.');
		}
	}

	public function boot(IBootContext $context): void {
		$session = $this->getContainer()->get(ISession::class);
		$userSession = $this->getContainer()->get(IUserSession::class);

		if ($userSession->isLoggedIn()) {
			if (!$session->exists('is-' . self::APP_ID)) {
				return;
			}

			Util::addStyle(self::APP_ID, 'hitobitologin.hidepasswordform');
		}

		try {
			$context->injectFn($this->registerRedirect(...));
		} catch (\Throwable $e) {
		}
	}

	private function registerRedirect(
		IRequest $request,
		LoggerInterface $logger,
		SettingsService $settingsService,
	): void {
		// Handle immediate redirect to the Hitobito instance if setting is enabled
		$isLoginMask = false;
		try {
			$isLoginMask = $request->getPathInfo() === '/login' && $request->getParam('direct') !== '1';
		} catch (\Exception $e) {
			// in case any errors happen when checking for the path do not apply redirect logic as it is only needed for the login
		}

		if ($isLoginMask && !$settingsService->isAppSetup()) {
			$logger->warning('HitobitoLogin app is not set up yet. Please configure the app in the admin settings.');
			return;
		}

		if ($isLoginMask && $settingsService->hasGeneralOption(SettingsService::GENERAL_OPTION_USE_AS_DEFAULT_LOGIN)) {
			$originUrl = $request->getParam('redirect_url');

			$targetUrl = $settingsService->generateAuthUrl($originUrl);
			header('Location: ' . $targetUrl);
			exit();
		}
	}
}
