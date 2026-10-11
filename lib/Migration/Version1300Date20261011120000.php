<?php

declare(strict_types=1);

namespace OCA\HitobitoLogin\Migration;

use Closure;
use OCA\HitobitoLogin\Group\HitobitoGroupBackend;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the table holding the group memberships assigned by this app.
 *
 * @psalm-suppress UnusedClass
 */
final class Version1300Date20261011120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(HitobitoGroupBackend::TABLE)) {
			return null;
		}

		$table = $schema->createTable(HitobitoGroupBackend::TABLE);
		$table->addColumn('gid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->setPrimaryKey(['gid', 'uid']);
		$table->addIndex(['uid'], 'hitobito_gu_uid_idx');

		return $schema;
	}
}
