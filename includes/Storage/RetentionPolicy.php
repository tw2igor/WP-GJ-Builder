<?php

namespace WPGJBuilder\Storage;

use WPGJBuilder\Core\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Политика хранения ревизий (раздел 11/12 спеки: "политика ревизий с
 * лимитами и плановой очисткой"). Публикация создаёт НОВУЮ строку в
 * {prefix}wpb_documents на каждый вызов (см. DocumentRepository::publish())
 * и ничего не удаляет сама — без этого класса таблица растёт бесконечно.
 * Текущая опубликованная версия (`is_current = 1`) НИКОГДА не удаляется
 * этой политикой — лимит применяется только к истории публикаций.
 */
class RetentionPolicy {

	const OPTION_KEEP_COUNT = 'wpgjb_revision_retention_count';
	const DEFAULT_KEEP_COUNT = 10;
	const CRON_HOOK = 'wpgjb_prune_revisions';

	public static function register_hooks() {
		add_action( self::CRON_HOOK, array( self::class, 'prune_all' ) );
		// Раздел 3 плана: удаление поста должно чистить его документы —
		// иначе строки {prefix}wpb_documents переживают удалённую страницу
		// без единого способа до них добраться штатными средствами WP.
		add_action( 'before_delete_post', array( self::class, 'purge_for_deleted_post' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function keep_count(): int {
		$value = (int) get_option( self::OPTION_KEEP_COUNT, self::DEFAULT_KEEP_COUNT );
		return $value > 0 ? $value : self::DEFAULT_KEEP_COUNT;
	}

	public static function set_keep_count( int $value ) {
		update_option( self::OPTION_KEEP_COUNT, max( 1, $value ), false );
	}

	/** Плановая (cron) очистка — все документы сразу. */
	public static function prune_all(): int {
		global $wpdb;
		$table = DocumentsTable::table_name();
		$keep  = self::keep_count();

		$groups = $wpdb->get_results( "SELECT DISTINCT post_id, type FROM {$table} WHERE revision_type = 'publish'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$deleted_total = 0;
		foreach ( $groups as $group ) {
			$deleted_total += self::prune_document( (int) $group->post_id, $group->type, $keep );
		}

		if ( $deleted_total > 0 ) {
			Diagnostics::log(
				'revision-cleanup',
				sprintf( 'Плановая очистка ревизий: удалено %d строк сверх лимита в %d публикаций на документ.', $deleted_total, $keep ),
				array( 'keep' => $keep, 'deleted' => $deleted_total )
			);
		}

		return $deleted_total;
	}

	/** Очистка одного документа — переиспользуется cron'ом и ручным действием. */
	public static function prune_document( int $post_id, string $type, ?int $keep = null ): int {
		global $wpdb;
		$table = DocumentsTable::table_name();
		$keep  = $keep ?? self::keep_count();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND type = %s AND revision_type = 'publish' AND is_current = 0 ORDER BY updated_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$type
			)
		);

		$to_delete = array_slice( $ids, $keep );
		if ( empty( $to_delete ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $to_delete ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $to_delete ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return count( $to_delete );
	}

	/** Удаляет ВСЕ строки документа (любой type/revision_type) при удалении поста. */
	public static function purge_for_deleted_post( int $post_id ) {
		global $wpdb;
		$table  = DocumentsTable::table_name();
		$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $count ) {
			return;
		}
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
		Diagnostics::log( 'revision-cleanup', sprintf( 'Удалён пост #%d — очищено %d строк документов.', $post_id, $count ), array( 'post_id' => $post_id, 'deleted' => $count ) );
	}
}
