<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\AppInfo;

use OCA\HitobitoLogin\AlternativeLogin\HitobitoLogin;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hitobitologin';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$config = $this->getContainer()->get(IConfig::class);
		$generalSettings = (array)$config->getSystemValue(Application::APP_ID);

		// TODO: Create settings validator and use it here
		if ($generalSettings['base_url'] && $generalSettings['client_id'] && $generalSettings['client_secret'] && $generalSettings['login_button_text']) {
			$context->registerAlternativeLogin(HitobitoLogin::class);
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
	}
}
