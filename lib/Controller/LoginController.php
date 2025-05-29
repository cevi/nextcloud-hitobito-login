<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Controller;

use OC\Authentication\Token\IProvider;
use OC\User\Session as OC_UserSession;
use OCA\HitobitoLogin\AppInfo\Application;
use OCA\HitobitoLogin\Service\ProvisioningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

class LoginController extends Controller {
	private const ACCESS_TOKEN = 'hitobito.access_token';

	public function __construct(
		IRequest $request,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private IClientService $clientService,
		private ISession $session,
		private IEventDispatcher $eventDispatcher,
		private IProvider $authTokenProvider,
		private IUserSession $userSession,
		private IUserManager $userManager,
		private ProvisioningService $provisioningService,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function buildErrorTemplateResponse(string $message, int $statusCode): TemplateResponse {
		$response = new TemplateResponse(
			'',
			'error',
			[
				'errors' => [
					['error' => $message],
				],
			],
			TemplateResponse::RENDER_AS_ERROR
		);
		$response->setStatus($statusCode);

		return $response;
	}

	private function isSecure(): bool {
		// no restriction in debug mode
		return $this->config->getSystemValueBool('debug', false) || $this->request->getServerProtocol() === 'https';
	}

	private function authenticateUser(IUser $user): bool {
		// Copied from https://github.com/nextcloud/user_oidc/blob/main/lib/Controller/LoginController.php#L286

		$this->userSession->setUser($user);
		if (!$this->userSession instanceof OC_UserSession) {
			return false;
		}

		// TODO server should/could be refactored so we don't need to manually create the user session and dispatch the login-related events
		// Warning! If GSS is used, it reacts to the BeforeUserLoggedInEvent and handles the redirection itself
		// So nothing after dispatching this event will be executed
		$this->eventDispatcher->dispatchTyped(new BeforeUserLoggedInEvent($user->getUID(), null));

		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());
		$this->userSession->createRememberMeToken($user);

		// prevent password confirmation
		if (defined(IToken::class . '::SCOPE_SKIP_PASSWORD_VALIDATION')) {
			$token = $this->authTokenProvider->getToken($this->session->getId());
			$scope = $token->getScopeAsArray();
			$scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] = true;
			$token->setScope($scope);
			$this->authTokenProvider->updateToken($token);
		}

		$this->eventDispatcher->dispatchTyped(new UserLoggedInEvent($user, $user->getUID(), null, false));

		$user->updateLastLoginTimestamp();

		// Set last password confirm to the future as we don't have passwords to confirm against with SSO
		$this->session->set('last-password-confirm', strtotime('+4 year', time()));
		$this->session->set('is-' . Application::APP_ID, true);

		return true;
	}

	private function getRedirectResponse(?string $redirectUrl = null): RedirectResponse {
		return new RedirectResponse(
			$redirectUrl === null
				? $this->urlGenerator->getBaseUrl()
				: preg_replace('/^https?:\/\/[^\/]+/', '', $redirectUrl)
		);
	}

	#[FrontpageRoute(verb: 'GET', url: '/login/oauth')]
	#[PublicPage]
	#[NoCSRFRequired]
	#[UseSession]
	public function oauth(string $code, ?string $originUrl = null): Response {
		if ($this->userSession->isLoggedIn()) {
			return $this->getRedirectResponse($originUrl);
		}
		if (!$this->isSecure()) {
			return $this->buildErrorTemplateResponse(
				$this->l10n->t('You must access Nextcloud with HTTPS to use Hitobito login.'),
				Http::STATUS_FORBIDDEN
			);
		}

		$this->logger->debug('Initiating Hitobito login process');

		$client = $this->clientService->newClient();
		$generalSettings = (array)$this->config->getSystemValue(Application::APP_ID);

		$baseUrl = $generalSettings['base_url'];
		$clientId = $generalSettings['client_id'];
		$clientSecret = $generalSettings['client_secret'];

		$redirectUriParams = '';
		if ($originUrl !== null) {
			$redirectUriParams = http_build_query(['originUrl' => $originUrl]);
		}
		$redirectUri = sprintf(
			'%s://%s%s?%s',
			$this->request->getServerProtocol(),
			$this->request->getServerHost(),
			$this->request->getRawPathInfo(),
			$redirectUriParams,
		);

		if (empty($baseUrl) || empty($clientId) || empty($clientSecret)) {
			return $this->buildErrorTemplateResponse(
				$this->l10n->t('Missing configuration for Hitobito login'),
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if (empty($code)) {
			return $this->buildErrorTemplateResponse(
				$this->l10n->t('Missing code parameter'),
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$tokenResponse = $client->post("{$baseUrl}/oauth/token", [
			'headers' => [
				'Accept' => 'application/json'
			],
			'form_params' => [
				'grant_type' => 'authorization_code',
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'redirect_uri' => $redirectUri,
				'code' => $code
			]
		]);

		$tokenData = json_decode($tokenResponse->getBody());
		$accessToken = $tokenData->access_token;

		$profileResponse = $client->get("{$baseUrl}/oauth/profile", [
			'headers' => [
				'Accept' => 'application/json',
				'Authorization' => "Bearer $accessToken",
				'X-Scope' => 'with_roles'
			]
		]);

		$profileData = json_decode($profileResponse->getBody());

		$mappedGroupIDs = $this->provisioningService->getMappedGroups($profileData);
		if (in_array('block_unmapped', $generalSettings['options']) && count($mappedGroupIDs) === 0) {
			$this->logger->warning(
				'User has no mapped groups and block users without groups is enabled',
				['hitobito_id' => $profileData->id]
			);

			return $this->buildErrorTemplateResponse(
				$this->l10n->t('Access denied: You have no groups'),
				Http::STATUS_FORBIDDEN
			);
		}

		/** @var IUser $user */
		$user = null;

		$existingUsers = $this->config->getUsersForUserValue(Application::APP_ID, 'hitobito_id', $profileData->id);
		if (count($existingUsers) > 1) {
			$this->logger->error('Multiple users found for Hitobito ID', ['hitobito_id' => $profileData->id]);

			return $this->buildErrorTemplateResponse(
				$this->l10n->t('Multiple users found for Hitobito ID %1$s', [$profileData->id]),
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
		if (isset($existingUsers[0])) {
			$user = $this->userManager->get($existingUsers[0]);
		} elseif (in_array('email_lookup', $generalSettings['options'])) {
			$usersWithEmail = $this->userManager->getByEmail($profileData->email);
			if (count($usersWithEmail) > 1) {
				$this->logger->error('Multiple users found for email', ['email' => $profileData->email]);

				return $this->buildErrorTemplateResponse(
					$this->l10n->t('Multiple users found for email %1$s', [$profileData->email]),
					Http::STATUS_INTERNAL_SERVER_ERROR
				);
			}

			if (isset($usersWithEmail[0])) {
				$user = $usersWithEmail[0];
			}
		}

		$user = $this->provisioningService->provisionUser($profileData, $user, $mappedGroupIDs);

		if (!$this->authenticateUser($user)) {
			return $this->buildErrorTemplateResponse(
				$this->l10n->t('Failed to authenticate user'),
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		$this->session->set(self::ACCESS_TOKEN, $accessToken);

		if ($originUrl) {
			return $this->getRedirectResponse($originUrl);
		}

		return new RedirectResponse($this->urlGenerator->linkToDefaultPageUrl());
	}
}
