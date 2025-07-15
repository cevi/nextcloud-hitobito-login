<?php

namespace OCA\HitobitoLogin\AlternativeLogin;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\Authentication\IAlternativeLogin;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

class HitobitoLogin implements IAlternativeLogin {
	private $label = '';
	private $link = '';
	private $cssClass = '';

	public function __construct(
		private IRequest $request,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
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
		$generalSettings = (array)$this->config->getSystemValue(Application::APP_ID);

		$baseUrl = $generalSettings['base_url'];

		$originUrl = $this->request->getParam('redirect_url');
		$redirectUriParams = '';
		if ($originUrl) {
			$redirectUriParams = '?' . http_build_query(['originUrl' => $originUrl]);
		}
		$redirectUri = urlencode($this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.oauth') . $redirectUriParams);
		$scope = 'with_roles';

		$this->label = $generalSettings['login_button_text'];
		$this->link = "$baseUrl/oauth/authorize?response_type=code&client_id={$generalSettings['client_id']}&redirect_uri=$redirectUri&scope=$scope";
	}
}
