<?php

namespace WPGJBuilder\Storage;

use WPGJBuilder\Blocks\BlockFaultTolerance;
use WPGJBuilder\Core\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + optimistic locking над {prefix}wpb_documents (раздел 3, 5.5 спеки).
 * "draft" — одна обновляемая строка на сессию редактирования (is_current=1,
 * перезаписывается каждым автосохранением). "publish" — новая строка на
 * каждую публикацию, все хранятся (очистка по ретеншну — Phase 6, не здесь).
 */
class DocumentRepository {

	const STATUS_OK     = 'ok';
	const STATUS_CONFLICT = 'conflict';
	const STATUS_FROZEN = 'frozen';

	/** @var MigrationRunner */
	private $migrations;

	public function __construct( MigrationRunner $migrations ) {
		$this->migrations = $migrations;
	}

	/**
	 * Текущая строка ('draft' или 'publish') без применения миграций —
	 * сырое чтение, используется внутренне и диагностическим экраном.
	 */
	public function get_current_row( int $post_id, string $type, string $revision_type ) {
		global $wpdb;
		$table = DocumentsTable::table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND type = %s AND revision_type = %s AND is_current = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$type,
				$revision_type
			),
			ARRAY_A
		);
	}

	/**
	 * Документ для редактирования: берёт текущий draft, если его нет —
	 * последний publish как отправную точку новой сессии. Мигрирует
	 * project_json до актуальной schema_version; при неудаче миграции
	 * НЕ бросает исключение и НЕ трогает post_content — возвращает
	 * status=frozen, страница посетителя остаётся нетронутой (структурная
	 * гарантия дуального хранения, раздел 12).
	 *
	 * @return array{status:string, project_data:array|null, doc_version:int|null, row_id:int|null, error:string|null}
	 */
	public function get_for_edit( int $post_id, string $type ) {
		$row = $this->get_current_row( $post_id, $type, 'draft' );
		if ( ! $row ) {
			$row = $this->get_current_row( $post_id, $type, 'publish' );
		}

		if ( ! $row ) {
			return array(
				'status'       => self::STATUS_OK,
				'project_data' => null,
				'doc_version'  => null,
				'row_id'       => null,
				'error'        => null,
			);
		}

		$decoded = json_decode( $row['project_json'], true );
		if ( ! is_array( $decoded ) ) {
			Diagnostics::log( 'migration', 'project_json повреждён (невалидный JSON)', array( 'post_id' => $post_id, 'type' => $type, 'row_id' => $row['id'] ) );
			return array(
				'status'       => self::STATUS_FROZEN,
				'project_data' => null,
				'doc_version'  => (int) $row['doc_version'],
				'row_id'       => (int) $row['id'],
				'error'        => 'project_json повреждён: невалидный JSON.',
			);
		}

		$result = $this->migrations->migrate( $decoded, (int) $row['schema_version'], WPGJB_DB_SCHEMA_VERSION );

		if ( ! $result['ok'] ) {
			return array(
				'status'       => self::STATUS_FROZEN,
				'project_data' => null,
				'doc_version'  => (int) $row['doc_version'],
				'row_id'       => (int) $row['id'],
				'error'        => $result['error'],
			);
		}

		// Раздел 12: документ мигрировал успешно, но МОГ содержать ссылку на
		// блок, удалённый из библиотеки позже, чем документ сохранялся — без
		// этого редактор получил бы узел неизвестного типа и потенциально
		// упал бы на его рендере/выделении. Карантин здесь не персистится
		// сам по себе (только следующее сохранение зафиксирует результат) —
		// это защита СЕССИИ редактирования, а не тихая перезапись чужих данных.
		list( $quarantined_data, ) = BlockFaultTolerance::quarantine( $result['data'] );

		return array(
			'status'       => self::STATUS_OK,
			'project_data' => $quarantined_data,
			'doc_version'  => (int) $row['doc_version'],
			'row_id'       => (int) $row['id'],
			'error'        => null,
		);
	}

	/**
	 * @param int|null $expected_doc_version null для первого сохранения новой сессии
	 * @return array{status:string, doc_version:int|null, server_row:array|null}
	 */
	public function save_draft( int $post_id, string $type, array $project_data, $expected_doc_version, int $user_id ) {
		global $wpdb;
		$table = DocumentsTable::table_name();
		$now   = current_time( 'mysql', true );
		$json  = wp_json_encode( $project_data );

		$existing = $this->get_current_row( $post_id, $type, 'draft' );

		if ( ! $existing ) {
			$wpdb->insert(
				$table,
				array(
					'post_id'        => $post_id,
					'type'           => $type,
					'revision_type'  => 'draft',
					'is_current'     => 1,
					'project_json'   => $json,
					'schema_version' => WPGJB_DB_SCHEMA_VERSION,
					'doc_version'    => 1,
					'updated_at'     => $now,
					'updated_by'     => $user_id,
				),
				array( '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d' )
			);

			return array(
				'status'      => self::STATUS_OK,
				'doc_version' => 1,
				'server_row'  => null,
			);
		}

		if ( null !== $expected_doc_version && (int) $existing['doc_version'] !== (int) $expected_doc_version ) {
			return array(
				'status'      => self::STATUS_CONFLICT,
				'doc_version' => (int) $existing['doc_version'],
				'server_row'  => $existing,
			);
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET project_json = %s, schema_version = %d, updated_at = %s, updated_by = %d, doc_version = doc_version + 1 WHERE id = %d AND doc_version = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$json,
				WPGJB_DB_SCHEMA_VERSION,
				$now,
				$user_id,
				$existing['id'],
				$existing['doc_version']
			)
		);

		if ( 0 === $updated ) {
			// Кто-то сохранил между нашим чтением и записью — гонка, тот же conflict-путь.
			$fresh = $this->get_current_row( $post_id, $type, 'draft' );
			return array(
				'status'      => self::STATUS_CONFLICT,
				'doc_version' => $fresh ? (int) $fresh['doc_version'] : null,
				'server_row'  => $fresh,
			);
		}

		return array(
			'status'      => self::STATUS_OK,
			'doc_version' => (int) $existing['doc_version'] + 1,
			'server_row'  => null,
		);
	}

	/**
	 * Новая постоянная ревизия публикации (raздел 5.5). Старые публикации
	 * не удаляются здесь — очистка по политике хранения (Phase 6).
	 */
	public function publish( int $post_id, string $type, array $project_data, int $user_id ) {
		global $wpdb;
		$table = DocumentsTable::table_name();
		$now   = current_time( 'mysql', true );

		$wpdb->update(
			$table,
			array( 'is_current' => 0 ),
			array( 'post_id' => $post_id, 'type' => $type, 'revision_type' => 'publish', 'is_current' => 1 ),
			array( '%d' ),
			array( '%d', '%s', '%s', '%d' )
		);

		$wpdb->insert(
			$table,
			array(
				'post_id'        => $post_id,
				'type'           => $type,
				'revision_type'  => 'publish',
				'is_current'     => 1,
				'project_json'   => wp_json_encode( $project_data ),
				'schema_version' => WPGJB_DB_SCHEMA_VERSION,
				'doc_version'    => 1,
				'updated_at'     => $now,
				'updated_by'     => $user_id,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}
}
