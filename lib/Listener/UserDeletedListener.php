<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Listener;

use OCA\HitobitoLogin\Group\HitobitoGroupBackend;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Drops the app-assigned memberships of a deleted user.
 *
 * Nextcloud already removes a deleted user from all groups (which reaches this app's backend through
 * IGroup::removeUser()); this listener makes sure no rows are left behind in any case.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private HitobitoGroupBackend $groupBackend,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$this->groupBackend->removeAllMembershipsOfUser($event->getUser()->getUID());
	}
}
