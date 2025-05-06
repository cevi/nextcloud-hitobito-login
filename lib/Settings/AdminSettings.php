<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Settings;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	private static array $defaultGeneralSettings = [
		'options' => [],
		'base_url' => '',
		'client_id' => '',
		'client_secret' => '',
		'login_button_text' => 'Hitobito Login',
	];

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private IURLGenerator $urlGenerator,
		private IInitialState $initialStateService,
	) {
	}

	public function getForm(): TemplateResponse {
		$generalSettings = (array)$this->config->getSystemValue(Application::APP_ID, self::$defaultGeneralSettings);
		$groupMappings = $this->appConfig->getValueArray(Application::APP_ID, 'group_mappings');

		$this->initialStateService->provideInitialState('admin_settings_state', [
			'save_admin_settings_url' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.settings.saveAdmin'),
			'general_settings' => $generalSettings,
			'group_mappings' => $groupMappings,
		]);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-settings-admin');

		return new TemplateResponse(Application::APP_ID, 'settings/admin');
	}

	public function getSection(): string {
		return Application::APP_ID; // Name of the previously created section.
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 *             the admin section. The forms are arranged in ascending order of the
	 *             priority values. It is required to return a value between 0 and 100.
	 *
	 * E.g.: 70
	 */
	public function getPriority(): int {
		return 10;
	}
}
