<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\AppInfo;

use OCA\HitobitoLogin\AlternativeLogin\HitobitoLogin;
use OCA\HitobitoLogin\Group\HitobitoGroupBackend;
use OCA\HitobitoLogin\Listener\GroupDeletedListener;
use OCA\HitobitoLogin\Listener\UserDeletedListener;
use OCA\HitobitoLogin\Service\SettingsService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\User\Events\UserDeletedEvent;
use OCP\Util;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hitobitologin';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(GroupDeletedEvent::class, GroupDeletedListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);

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
		try {
			$context->injectFn($this->registerGroupBackend(...));
		} catch (\Throwable $e) {
			/** @var LoggerInterface $logger */
			$logger = $this->getContainer()->get(LoggerInterface::class);
			$logger->error('Could not register the hitobito group backend', ['exception' => $e]);
		}

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

	/**
	 * Registers the group backend holding the memberships assigned by this app, so that they are visible to
	 * Nextcloud like any other group membership.
	 */
	private function registerGroupBackend(IGroupManager $groupManager, HitobitoGroupBackend $groupBackend): void {
		$groupManager->addBackend($groupBackend);
	}

	private function registerRedirect(
		IRequest $request,
		LoggerInterface $logger,
		SettingsService $settingsService,
	): void {
		// Handle immediate redirect to the hitobito instance if setting is enabled
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
