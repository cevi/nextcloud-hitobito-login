<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Listener;

use OCA\HitobitoLogin\Group\HitobitoGroupBackend;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Drops the app-assigned memberships of a group once the group has been deleted in its own backend.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private HitobitoGroupBackend $groupBackend,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GroupDeletedEvent) {
			return;
		}

		$this->groupBackend->deleteGroup($event->getGroup()->getGID());
	}
}
