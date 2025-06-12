<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Controller;

use OCA\HitobitoLogin\AppInfo\Application;
use OCA\HitobitoLogin\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;

class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private SettingsService $settingsService,
		private IGroupManager $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'PUT', url: '/settings/admin')]
	public function saveAdmin(): Response {
		$generalSettings = $this->request->getParam('general_settings');
		$groupMappings = $this->request->getParam('group_mappings');

		$success = $this->settingsService->saveGeneralSettings($generalSettings);
		if (!$success) {
			return new JSONResponse(['success' => false]);
		}

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
