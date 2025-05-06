<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Controller;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;

class SettingsController extends Controller {
	private $knownGeneralOptions = [
		'prune_groups',
		'block_unmapped',
		'email_lookup'
	];

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'PUT', url: '/settings/admin')]
	public function saveAdmin(): Response {
		$generalSettings = $this->request->getParam('general_settings');
		$groupMappings = $this->request->getParam('group_mappings');

		$saveGeneralSettings = [];

		foreach ($generalSettings['options'] as $key => $option) {
			if (!in_array($option, $this->knownGeneralOptions)) {
				return new JSONResponse(['success' => false]);
			}
		}
		$saveGeneralSettings['options'] = $generalSettings['options'];

		if (
			!isset($generalSettings['base_url']) ||
			!is_string($generalSettings['base_url']) ||
			empty(trim($generalSettings['base_url'])) ||
			!filter_var(trim($generalSettings['base_url']), FILTER_VALIDATE_URL)
		) {
			return new JSONResponse(['success' => false]);
		}
		$saveGeneralSettings['base_url'] = trim($generalSettings['base_url']);

		if (!isset($generalSettings['client_id']) || !is_string($generalSettings['client_id']) || empty(trim($generalSettings['client_id']))) {
			return new JSONResponse(['success' => false]);
		}
		$saveGeneralSettings['client_id'] = trim($generalSettings['client_id']);

		if (!isset($generalSettings['client_secret']) || !is_string($generalSettings['client_secret']) || empty(trim($generalSettings['client_secret']))) {
			return new JSONResponse(['success' => false]);
		}
		$saveGeneralSettings['client_secret'] = trim($generalSettings['client_secret']);

		if (!isset($generalSettings['login_button_text']) || !is_string($generalSettings['login_button_text']) || empty(trim($generalSettings['login_button_text']))) {
			return new JSONResponse(['success' => false]);
		}
		$saveGeneralSettings['login_button_text'] = trim($generalSettings['login_button_text']);

		$this->config->setSystemValue(Application::APP_ID, $saveGeneralSettings);

		$saveGroupMappings = [];

		if (!is_array($groupMappings)) {
			return new JSONResponse(['success' => false]);
		}

		foreach ($groupMappings as $key => $groupMapping) {
			if (!isset($groupMapping['group']) || !is_string($groupMapping['group']) || empty(trim($groupMapping['group']))) {
				return new JSONResponse(['success' => false]);
			}

			if (!isset($groupMapping['role']) || !is_string($groupMapping['role']) || empty(trim($groupMapping['role']))) {
				return new JSONResponse(['success' => false]);
			}

			if (!isset($groupMapping['targets']) || !is_array($groupMapping['targets'])) {
				return new JSONResponse(['success' => false]);
			}

			foreach ($groupMapping['targets'] as $target) {
				if (!is_string($target) || empty(trim($target)) || !$this->groupManager->groupExists($target)) {
					return new JSONResponse(['success' => false]);
				}
			}

			$saveGroupMappings[] = [
				'group' => trim($groupMapping['group']),
				'role' => trim($groupMapping['role']),
				'targets' => $groupMapping['targets'],
			];
		}

		$this->appConfig->setValueArray(Application::APP_ID, 'group_mappings', $saveGroupMappings);

		return new JSONResponse(['success' => true]);
	}
}
