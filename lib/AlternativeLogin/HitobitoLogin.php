<?php

namespace OCA\HitobitoLogin\AlternativeLogin;

use OCA\HitobitoLogin\Service\SettingsService;
use OCP\Authentication\IAlternativeLogin;
use OCP\IRequest;

class HitobitoLogin implements IAlternativeLogin {
	private $label = '';
	private $link = '';
	private $cssClass = '';

	public function __construct(
		private IRequest $request,
		private SettingsService $settingsService,
	) {
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getLink(): string {
		return $this->link;
	}

	public function getClass(): string {
		return $this->cssClass;
	}

	public function load(): void {
		$originUrl = $this->request->getParam('redirect_url');

		$this->label = $this->settingsService->getGeneralSetting(SettingsService::GENERAL_LOGIN_BUTTON_TEXT);
		$this->link = $this->settingsService->generateAuthUrl($originUrl);
	}
}
