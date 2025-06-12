<?php

namespace OCA\HitobitoLogin\Service;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\HintException;
use OCP\IConfig;
use OCP\IURLGenerator;

class SettingsService {
	public const GENERAL_OPTIONS = 'options';
	public const GENERAL_BASE_URL = 'base_url';
	public const GENERAL_CLIENT_ID = 'client_id';
	public const GENERAL_CLIENT_SECRET = 'client_secret';
	public const GENERAL_LOGIN_BUTTON_TEXT = 'login_button_text';

	public const GENERAL_OPTION_PRUNE_GROUPS = 'prune_groups';
	public const GENERAL_OPTION_BLOCK_UNMAPPED = 'block_unmapped';
	public const GENERAL_OPTION_EMAIL_LOOKUP = 'email_lookup';

	public const KNOWN_GENERAL_OPTIONS = [
		self::GENERAL_OPTION_PRUNE_GROUPS,
		self::GENERAL_OPTION_BLOCK_UNMAPPED,
		self::GENERAL_OPTION_EMAIL_LOOKUP,
	];

	protected array $generalSettings = [];

	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
		$this->generalSettings = (array)$this->config->getSystemValue(Application::APP_ID);
	}

	public function getGeneralSetting(string $key): mixed {
		if (empty($this->generalSettings[$key])) {
			return null;
		}

		return $this->generalSettings[$key];
	}

	public function hasGeneralOption(string $option): bool {
		if (empty($this->generalSettings[self::GENERAL_OPTIONS]) || !is_array($this->generalSettings[self::GENERAL_OPTIONS])) {
			return false;
		}

		return in_array($option, $this->generalSettings[self::GENERAL_OPTIONS]);
	}

	/**
	 * @throws HintException
	 */
	public function saveGeneralSettings(array $updatedGeneralSettings): bool {
		$saveGeneralSettings = [];

		if (empty($updatedGeneralSettings)) {
			return false;
		}

		if (!empty($updatedGeneralSettings[self::GENERAL_OPTIONS])) {
			foreach ($updatedGeneralSettings[self::GENERAL_OPTIONS] as $key => $option) {
				if (!in_array($option, self::KNOWN_GENERAL_OPTIONS)) {
					return false;
				}
			}

			$saveGeneralSettings[self::GENERAL_OPTIONS] = $updatedGeneralSettings[self::GENERAL_OPTIONS];
		} else {
			$saveGeneralSettings[self::GENERAL_OPTIONS] = [];
		}

		if (
			!isset($updatedGeneralSettings[self::GENERAL_BASE_URL]) ||
			!is_string($updatedGeneralSettings[self::GENERAL_BASE_URL]) ||
			empty(trim($updatedGeneralSettings[self::GENERAL_BASE_URL])) ||
			!filter_var(trim($updatedGeneralSettings[self::GENERAL_BASE_URL]), FILTER_VALIDATE_URL)
		) {
			return false;
		}
		$saveGeneralSettings[self::GENERAL_BASE_URL] = trim($updatedGeneralSettings[self::GENERAL_BASE_URL]);

		if (
			!isset($updatedGeneralSettings[self::GENERAL_CLIENT_ID]) ||
			!is_string($updatedGeneralSettings[self::GENERAL_CLIENT_ID]) ||
			empty(trim($updatedGeneralSettings[self::GENERAL_CLIENT_ID]))
		) {
			return false;
		}
		$saveGeneralSettings[self::GENERAL_CLIENT_ID] = trim($updatedGeneralSettings[self::GENERAL_CLIENT_ID]);

		if (
			!isset($updatedGeneralSettings[self::GENERAL_CLIENT_SECRET]) ||
			!is_string($updatedGeneralSettings[self::GENERAL_CLIENT_SECRET]) ||
			empty(trim($updatedGeneralSettings[self::GENERAL_CLIENT_SECRET]))
		) {
			return false;
		}
		$saveGeneralSettings[self::GENERAL_CLIENT_SECRET] = trim($updatedGeneralSettings[self::GENERAL_CLIENT_SECRET]);

		if (
			!isset($updatedGeneralSettings[self::GENERAL_LOGIN_BUTTON_TEXT]) ||
			!is_string($updatedGeneralSettings[self::GENERAL_LOGIN_BUTTON_TEXT]) ||
			empty(trim($updatedGeneralSettings[self::GENERAL_LOGIN_BUTTON_TEXT]))
		) {
			return false;
		}
		$saveGeneralSettings[self::GENERAL_LOGIN_BUTTON_TEXT] = trim($updatedGeneralSettings[self::GENERAL_LOGIN_BUTTON_TEXT]);

		$this->config->setSystemValue(Application::APP_ID, $saveGeneralSettings);

		$this->generalSettings = (array)$this->config->getSystemValue(Application::APP_ID);

		return true;
	}

	public function isAppSetup(): bool {
		if (count($this->generalSettings) === 0) {
			return false;
		}

		if (
			empty($this->generalSettings[self::GENERAL_BASE_URL]) ||
			empty($this->generalSettings[self::GENERAL_CLIENT_ID]) ||
			empty($this->generalSettings[self::GENERAL_CLIENT_SECRET]) ||
			empty($this->generalSettings[self::GENERAL_LOGIN_BUTTON_TEXT])
		) {
			return false;
		}

		return true;
	}

	public function generateAuthUrl(?string $originUrl): ?string {
		if (!$this->isAppSetup()) {
			return null;
		}

		$baseUrl = $this->generalSettings[self::GENERAL_BASE_URL];
		$clientId = $this->generalSettings[self::GENERAL_CLIENT_ID];

		$redirectUriParams = '';
		if ($originUrl) {
			$redirectUriParams = '?' . http_build_query(['originUrl' => $originUrl]);
		}
		$redirectUri = urlencode($this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.oauth') . $redirectUriParams);
		$scope = 'with_roles';

		return "$baseUrl/oauth/authorize?response_type=code&client_id=$clientId&redirect_uri=$redirectUri&scope=$scope";
	}
}
