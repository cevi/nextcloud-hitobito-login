<?php

namespace OCA\HitobitoLogin\AlternativeLogin;

use OCP\Authentication\IAlternativeLogin;
use OCP\Util;

class DefaultLoginShow implements IAlternativeLogin {
    public function getLabel(): string { return 'Mit Benutzername & Passwort anmelden'; }
    public function getLink(): string { return '#body-login'; }
    public function getClass(): string { return 'button-vue button-vue--size-normal button-vue--text-only button-vue--vue-secondary button-vue--wide button primary'; }
    public function load(): void {
        Util::addStyle('hitobitologin', 'hide_default_login');
    }
}
