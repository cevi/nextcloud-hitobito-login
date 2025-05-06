<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Sections;

use OCA\HitobitoLogin\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Hitobito Login');
	}

	public function getPriority(): int {
		return 98;
	}
}
