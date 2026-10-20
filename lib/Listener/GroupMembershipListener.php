<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Listener;

use OCA\HitobitoLogin\Service\AssignedGroupsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserRemovedEvent;

/**
 * Removes memberships tracked by this app when they are removed by other means.
 * Tracked memberships of deleted users are removed by Nextcloud together with all other user values.
 *
 * @template-implements IEventListener<Event>
 */
class GroupMembershipListener implements IEventListener {
	public function __construct(
		private AssignedGroupsService $assignedGroupsService,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserRemovedEvent) {
			$this->assignedGroupsService->removeAssignedGroup($event->getUser()->getUID(), $event->getGroup()->getGID());
		} elseif ($event instanceof GroupDeletedEvent) {
			$this->assignedGroupsService->removeAssignedGroupFromAllUsers($event->getGroup()->getGID());
		}
	}
}
