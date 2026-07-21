<?php

namespace WPGJBuilder\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Схема {prefix}wpb_documents (раздел 3 спеки, план: "Технические решения",
 * пункт 2). Отдельная таблица, а не postmeta/options — dbDelta создаёт/
 * обновляет схему идемпотентно при активации и при повышении версии.
 */
class DocumentsTable {

	const OPTION_DB_VERSION = 'wpgjb_db_version';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wpb_documents';
	}

	public static function maybe_upgrade() {
		$installed = (int) get_option( self::OPTION_DB_VERSION, 0 );
		if ( $installed >= WPGJB_DB_SCHEMA_VERSION ) {
			return;
		}
		self::create_or_upgrade();
		update_option( self::OPTION_DB_VERSION, WPGJB_DB_SCHEMA_VERSION, false );
	}

	private static function create_or_upgrade() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta требует строгого форматирования: PRIMARY KEY с двумя
		// пробелами перед ним, каждый KEY на отдельной строке без запятой
		// в конце последнего поля перед закрывающей скобкой создания индекса.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			revision_type VARCHAR(10) NOT NULL,
			is_current TINYINT(1) NOT NULL DEFAULT 0,
			project_json LONGTEXT NOT NULL,
			schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			doc_version INT UNSIGNED NOT NULL DEFAULT 1,
			updated_at DATETIME NOT NULL,
			updated_by BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_post_type_rev (post_id, type, revision_type, is_current),
			KEY idx_post_updated (post_id, updated_at),
			KEY idx_type (type)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
