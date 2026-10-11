<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Group;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\ICountUsersBackend;
use OCP\Group\Backend\IDeleteGroupBackend;
use OCP\Group\Backend\INamedBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCP\IDBConnection;

/**
 * Group backend that holds exactly the group memberships assigned by this app.
 *
 * The groups themselves are regular Nextcloud groups (usually from the core database backend); this backend
 * only overlays memberships on them. Because the memberships live in a separate table, the app can tell which
 * memberships it created and only ever remove those, leaving memberships assigned by admins or other backends
 * untouched.
 *
 * IAddToGroupBackend is deliberately not implemented: IGroup::addUser() (admin UI, occ group:adduser) writes to
 * the first backend that can add users, which must remain the core backend so that manually assigned
 * memberships are never mistaken for app-assigned ones. The app writes through addMembership() instead.
 */
class HitobitoGroupBackend extends ABackend implements ICountUsersBackend, IDeleteGroupBackend, INamedBackend, IRemoveFromGroupBackend {
	public const BACKEND_NAME = 'hitobito';
	public const TABLE = 'hitobito_group_user';

	/** @var ?list<string> group ids with at least one membership, loaded lazily once per request */
	private ?array $groupIdCache = null;

	/** @var array<string, list<string>> group ids per user, loaded lazily per request */
	private array $userGroupsCache = [];

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function getBackendName(): string {
		return self::BACKEND_NAME;
	}

	/**
	 * @param string $uid
	 * @param string $gid
	 */
	public function inGroup($uid, $gid): bool {
		return in_array($gid, $this->getUserGroups($uid), true);
	}

	/**
	 * @param string $uid
	 * @return list<string>
	 */
	public function getUserGroups($uid): array {
		if ($uid === '') {
			return [];
		}

		if (!isset($this->userGroupsCache[$uid])) {
			$qb = $this->db->getQueryBuilder();
			$result = $qb->select('gid')
				->from(self::TABLE)
				->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
				->orderBy('gid', 'ASC')
				->executeQuery();

			$groups = [];
			while (($row = $result->fetch()) !== false) {
				$groups[] = (string)$row['gid'];
			}
			$result->closeCursor();

			$this->userGroupsCache[$uid] = $groups;
		}

		return $this->userGroupsCache[$uid];
	}

	/**
	 * @return list<string>
	 */
	public function getGroups(string $search = '', int $limit = -1, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('gid')
			->from(self::TABLE)
			->orderBy('gid', 'ASC');

		if ($search !== '') {
			$qb->where($qb->expr()->iLike('gid', $qb->createNamedParameter(
				'%' . $this->db->escapeLikeParameter($search) . '%'
			)));
		}
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		if ($offset > 0) {
			$qb->setFirstResult($offset);
		}

		$result = $qb->executeQuery();
		$groups = [];
		while (($row = $result->fetch()) !== false) {
			$groups[] = (string)$row['gid'];
		}
		$result->closeCursor();

		return $groups;
	}

	/**
	 * @param string $gid
	 */
	public function groupExists($gid): bool {
		return in_array($gid, $this->getAllGroupIds(), true);
	}

	/**
	 * @return list<string>
	 */
	public function groupsExists(array $gids): array {
		return array_values(array_intersect($this->getAllGroupIds(), $gids));
	}

	/**
	 * @param string $gid
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return list<string>
	 */
	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')
			->from(self::TABLE)
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->orderBy('uid', 'ASC');

		$this->applyUserSearch($qb, $search);

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		if ($offset > 0) {
			$qb->setFirstResult($offset);
		}

		$result = $qb->executeQuery();
		$users = [];
		while (($row = $result->fetch()) !== false) {
			$users[] = (string)$row['uid'];
		}
		$result->closeCursor();

		return $users;
	}

	public function countUsersInGroup(string $gid, string $search = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('uid'))
			->from(self::TABLE)
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));

		$this->applyUserSearch($qb, $search);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/** @psalm-suppress PossiblyUnusedReturnValue */
	public function removeFromGroup(string $uid, string $gid): bool {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->delete(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->executeStatement();

		$this->invalidateCaches();

		return $rows > 0;
	}

	/**
	 * Removes all memberships of the given group. The group itself belongs to another backend and is not touched.
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function deleteGroup(string $gid): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
			->executeStatement();

		$this->invalidateCaches();

		return true;
	}

	/**
	 * Records that this app assigned the user to the group. Idempotent.
	 */
	public function addMembership(string $uid, string $gid): void {
		$this->db->insertIgnoreConflict(self::TABLE, [
			'uid' => $uid,
			'gid' => $gid,
		]);

		$this->invalidateCaches();
	}

	public function removeAllMembershipsOfUser(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->executeStatement();

		$this->invalidateCaches();
	}

	/**
	 * @return list<string> all group ids with at least one app-assigned membership
	 */
	public function getAllGroupIds(): array {
		if ($this->groupIdCache === null) {
			$qb = $this->db->getQueryBuilder();
			$result = $qb->selectDistinct('gid')
				->from(self::TABLE)
				->executeQuery();

			$groups = [];
			while (($row = $result->fetch()) !== false) {
				$groups[] = (string)$row['gid'];
			}
			$result->closeCursor();

			$this->groupIdCache = $groups;
		}

		return $this->groupIdCache;
	}

	private function applyUserSearch(IQueryBuilder $qb, string $search): void {
		if ($search === '') {
			return;
		}

		$qb->andWhere($qb->expr()->iLike('uid', $qb->createNamedParameter(
			'%' . $this->db->escapeLikeParameter($search) . '%'
		)));
	}

	private function invalidateCaches(): void {
		$this->groupIdCache = null;
		$this->userGroupsCache = [];
	}
}
