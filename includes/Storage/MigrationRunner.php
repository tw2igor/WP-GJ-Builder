<?php

namespace WPGJBuilder\Storage;

use WPGJBuilder\Core\Diagnostics;
use WPGJBuilder\Storage\Migrations\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Прогоняет цепочку миграций project_json от текущей schema_version
 * документа до целевой (WPGJB_DB_SCHEMA_VERSION). Раздел 12 спеки:
 * "устаревший JSON прогоняется через цепочку миграций при открытии;
 * неудача миграции — блок замораживается в последнем валидном HTML,
 * инцидент логируется".
 *
 * Замораживание здесь структурное, а не отдельный код: этот класс
 * участвует только в пути редактирования (get_for_edit), а не в пути
 * показа страницы посетителю (тот берёт post_content напрямую и никогда
 * не обращается к project_json) — поэтому сбой миграции физически не
 * может сломать уже опубликованную страницу, только заблокировать её
 * повторное открытие в редакторе до ручного вмешательства.
 */
class MigrationRunner {

	/** @var Migration[] индексировано по from_version() */
	private $migrations = array();

	public function register( Migration $migration ) {
		$this->migrations[ $migration->from_version() ] = $migration;
	}

	/**
	 * @param array $project_data
	 * @param int   $from_version
	 * @param int   $to_version
	 * @return array{ok: bool, data: array|null, error: string|null}
	 */
	public function migrate( array $project_data, int $from_version, int $to_version ) {
		if ( $from_version === $to_version ) {
			return array(
				'ok'    => true,
				'data'  => $project_data,
				'error' => null,
			);
		}

		if ( $from_version > $to_version ) {
			$error = sprintf(
				'schema_version документа (%d) новее версии плагина (%d) — вероятно, документ создан более новой версией плагина.',
				$from_version,
				$to_version
			);
			Diagnostics::log( 'migration', $error, array( 'from' => $from_version, 'to' => $to_version ) );
			return array(
				'ok'    => false,
				'data'  => null,
				'error' => $error,
			);
		}

		$data    = $project_data;
		$version = $from_version;

		while ( $version < $to_version ) {
			if ( ! isset( $this->migrations[ $version ] ) ) {
				$error = sprintf( 'Отсутствует миграция с версии %d — цепочка разорвана.', $version );
				Diagnostics::log( 'migration', $error, array( 'from' => $from_version, 'to' => $to_version, 'stuck_at' => $version ) );
				return array(
					'ok'    => false,
					'data'  => null,
					'error' => $error,
				);
			}

			try {
				$data = $this->migrations[ $version ]->migrate( $data );
			} catch ( \Throwable $e ) {
				$error = sprintf( 'Миграция с версии %d упала: %s', $version, $e->getMessage() );
				Diagnostics::log( 'migration', $error, array( 'from' => $from_version, 'to' => $to_version, 'failed_at' => $version ) );
				return array(
					'ok'    => false,
					'data'  => null,
					'error' => $error,
				);
			}

			++$version;
		}

		return array(
			'ok'    => true,
			'data'  => $data,
			'error' => null,
		);
	}
}
