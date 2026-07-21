<?php

namespace WPGJBuilder\Storage\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * Контракт одной ступени миграции project_json. Каждая миграция знает,
 * с какой версии схемы она стартует, и переводит документ ровно на
 * следующую версию — цепочка вызывается по порядку MigrationRunner'ом.
 */
interface Migration {

	/** Версия схемы, которую эта миграция умеет принимать на вход. */
	public function from_version(): int;

	/**
	 * @param array $project_data декодированный project_json
	 * @return array мигрированные данные (schema_version -> from_version()+1)
	 * @throws \RuntimeException если данные не соответствуют ожидаемой форме
	 *         на входе — MigrationRunner ловит это и запускает freeze-on-failure.
	 */
	public function migrate( array $project_data ): array;
}
