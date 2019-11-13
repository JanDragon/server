<?php

declare(strict_types=1);

namespace OCA\Files\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Auto-generated migration step: Please modify to your needs!
 */
class Version11301Date20191113195931 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('user_transfer_ownership')) {
			return;
		}

		$table = $schema->createTable('user_transfer_ownership');
		$table->addColumn('id', 'integer', [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 4,
		]);
		$table->addColumn('source_user', 'string', [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('target_user', 'string', [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('path', 'text', [
			'notnull' => true,
		]);
		$table->setPrimaryKey(['id']);

		return $schema;
	}

}
