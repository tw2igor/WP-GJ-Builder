<?php

namespace WPGJBuilder\Storage;

use WPGJBuilder\SiteParts\PartsPostType;
use WPGJBuilder\Render\Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Раздел 12 спеки: "Чистое удаление... через штатный uninstall-механизм;
 * галочка «удалить данные при удалении» (по умолчанию выключена) +
 * отдельная явная «Полная очистка»." Общая реализация для ОБОИХ путей
 * (uninstall.php при выключении плагина и кнопка на экране "Настройки" при
 * активном плагине) — правила очистки не должны различаться между ними.
 *
 * Важно (раздел 12): "Контент сайтов (страницы с готовым HTML) при
 * удалении плагина остаётся читаемым" — post_content обычных
 * страниц/записей НИКОГДА не трогается, удаляются только
 * специфичные для плагина строки/опции/метаданные/посты.
 */
class Cleanup {

	const OPTION_DELETE_ON_UNINSTALL = 'wpgjb_delete_data_on_uninstall';

	/** @return array<string, int|bool> отчёт для UI/uninstall-лога */
	public static function full_cleanup(): array {
		global $wpdb;
		$report = array();

		$table = DocumentsTable::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$report['documents_table_dropped'] = true;

		// Части сайта (wpb_part) — собственные данные плагина без
		// самостоятельного публичного URL, не "контент сайта" в смысле
		// раздела 12; без плагина они не значат ничего.
		$parts = get_posts(
			array(
				'post_type'      => PartsPostType::POST_TYPE,
				'post_status'    => 'any',
				'numberposts'    => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $parts as $part_id ) {
			wp_delete_post( $part_id, true );
		}
		$report['parts_deleted'] = count( $parts );

		// Postmeta плагина на ОБЫЧНЫХ страницах/записях — сам post_content
		// (готовый статический HTML) не трогаем.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
				Publisher::META_BUILT,
				Publisher::META_CSS
			)
		);
		$report['postmeta_cleaned'] = true;

		foreach ( self::plugin_options() as $option ) {
			delete_option( $option );
		}
		$report['options_deleted'] = count( self::plugin_options() );

		RetentionPolicy::unschedule();
		$report['cron_unscheduled'] = true;

		// Транзиенты плагином сейчас не используются — очистка на случай
		// появления в будущих фазах (дрейф не должен пережить "полную очистку").
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_wpgjb_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wpgjb_' ) . '%'
			)
		);
		$report['transients_cleaned'] = true;

		return $report;
	}

	private static function plugin_options(): array {
		return array(
			self::OPTION_DELETE_ON_UNINSTALL,
			\WPGJBuilder\Core\Diagnostics::OPTION_LOG,
			RetentionPolicy::OPTION_KEEP_COUNT,
			DocumentsTable::OPTION_DB_VERSION,
		);
	}
}
