<?php

namespace WPGJBuilder\Tests\Phase6;

use WPGJBuilder\Blocks\BlockFaultTolerance;
use WPGJBuilder\Blocks\BlockSnapshotter;
use WPGJBuilder\Core\Diagnostics;
use WPGJBuilder\Render\PageAssets;
use WPGJBuilder\Render\Publisher;
use WPGJBuilder\Storage\DocumentRepository;
use WPGJBuilder\Storage\DocumentsTable;
use WPGJBuilder\Storage\MigrationRunner;
use WPGJBuilder\Storage\RetentionPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only ручная проверка Phase 6 (надёжность + очистка + per-page
 * ассеты): retention-прунинг ревизий, очистка при удалении поста,
 * отказоустойчивый рендер (карантин неизвестных/испорченных блоков) на
 * публикации и на загрузке документа в редактор, резолв JS-ассетов
 * использованных блоков и вынос базового CSS блоков из per-page блоба в
 * общий файл. "Полная очистка" (Cleanup::full_cleanup()) сюда намеренно
 * НЕ включена — она разрушительна (дропает таблицу документов целиком) и
 * проверяется отдельным ручным REST-вызовом, не автопрогоном.
 */
class Phase6Runner {

	public static function run() {
		return array(
			'retention_keeps_current_and_prunes_history' => self::check_retention_prunes_history(),
			'post_delete_purges_documents'                => self::check_purge_on_post_delete(),
			'publish_survives_unknown_block'              => self::check_publish_survives_unknown_block(),
			'publish_survives_structural_corruption'      => self::check_publish_survives_structural_corruption(),
			'editor_load_quarantines_missing_block'       => self::check_editor_load_quarantines_missing_block(),
			'page_assets_resolves_js_for_used_block'      => self::check_page_assets_resolves_js_for_used_block(),
			'page_assets_empty_for_block_without_js'      => self::check_page_assets_empty_for_block_without_js(),
			'publish_stores_small_page_css_not_block_style' => self::check_publish_stores_small_page_css(),
			'snapshot_matches_itself'                      => self::check_snapshot_matches_itself(),
			'snapshot_detects_mismatch'                    => self::check_snapshot_detects_mismatch(),
			'snapshot_no_baseline_reported_honestly'       => self::check_snapshot_no_baseline(),
		);
	}

	private static function make_page( string $title ): int {
		return wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	private static function cleanup_posts( array $ids ) {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	private static function repository(): DocumentRepository {
		return new DocumentRepository( new MigrationRunner() );
	}

	private static function insert_publish_row( int $post_id, string $type ) {
		global $wpdb;
		$table = DocumentsTable::table_name();
		$wpdb->update( $table, array( 'is_current' => 0 ), array( 'post_id' => $post_id, 'type' => $type, 'revision_type' => 'publish', 'is_current' => 1 ) );
		$wpdb->insert(
			$table,
			array(
				'post_id'        => $post_id,
				'type'           => $type,
				'revision_type'  => 'publish',
				'is_current'     => 1,
				'project_json'   => wp_json_encode( array( 'pages' => array() ) ),
				'schema_version' => 1,
				'doc_version'    => 1,
				'updated_at'     => current_time( 'mysql', true ),
				'updated_by'     => get_current_user_id(),
			)
		);
	}

	private static function check_retention_prunes_history() {
		$page_id = self::make_page( 'Phase6 Retention Page' );
		$type    = 'page';

		// 12 публикаций подряд — прямыми вставками строк (без прогона через
		// весь Publisher — здесь важна только политика хранения).
		for ( $i = 0; $i < 12; $i++ ) {
			self::insert_publish_row( $page_id, $type );
		}

		global $wpdb;
		$table         = DocumentsTable::table_name();
		$before_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND type = %s AND revision_type = 'publish'", $page_id, $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$deleted      = RetentionPolicy::prune_document( $page_id, $type, 5 );
		$after_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND type = %s AND revision_type = 'publish'", $page_id, $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current_kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND type = %s AND revision_type = 'publish' AND is_current = 1", $page_id, $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		self::cleanup_posts( array( $page_id ) );

		// keep=5 применяется к истории (is_current=0), текущая версия — сверх лимита.
		$ok = 12 === $before_count && 6 === $after_count && 1 === $current_kept && $deleted === 6;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'из 12 публикаций после прунинга с лимитом 5 осталось 6 строк (5 истории + 1 текущая), текущая не тронута'
				: 'ожидалось 12 -> 6 строк (лимит 5 + is_current), несоответствие',
			'result' => array( 'before' => $before_count, 'after' => $after_count, 'deleted' => $deleted, 'current_kept' => $current_kept ),
		);
	}

	private static function check_purge_on_post_delete() {
		$page_id = self::make_page( 'Phase6 Purge Page' );
		self::insert_publish_row( $page_id, 'page' );

		global $wpdb;
		$table        = DocumentsTable::table_name();
		$before_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $page_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_delete_post( $page_id, true ); // before_delete_post -> RetentionPolicy::purge_for_deleted_post()

		$after_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $page_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ok = 1 === $before_count && 0 === $after_count;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'удаление поста очистило все строки документов этого post_id (before_delete_post)'
				: 'ожидалось, что удаление поста уберёт связанные строки документов',
			'result' => array( 'before' => $before_count, 'after' => $after_count ),
		);
	}

	private static function corrupt_project_data(): array {
		return array(
			'pages' => array(
				array(
					'frames' => array(
						array(
							'component' => array(
								'type'       => 'wrapper',
								'components' => array(
									array(
										'type'       => 'div',
										'attributes' => array( 'data-wpb-block' => 'test-cta', 'data-wpb-values' => '{}' ),
										'components' => array(),
									),
									array(
										'type'       => 'div',
										'attributes' => array( 'data-wpb-block' => 'does-not-exist-block-id' ),
										'components' => array(),
									),
								),
							),
						),
					),
				),
			),
			'styles' => array(),
		);
	}

	private static function structurally_broken_project_data(): array {
		return array(
			'pages' => array(
				array(
					'frames' => array(
						array(
							'component' => array(
								'type'       => 'wrapper',
								'components' => array(
									'this is not a component array — structural corruption',
									array(
										'type'       => 'div',
										'attributes' => array( 'data-wpb-block' => 'test-cta', 'data-wpb-values' => '{}' ),
										'components' => array(),
									),
								),
							),
						),
					),
				),
			),
			'styles' => array(),
		);
	}

	private static function check_publish_survives_unknown_block() {
		$page_id = self::make_page( 'Phase6 Fault Tolerance Page' );

		$before_count = count( array_filter( Diagnostics::recent( 500 ), fn( $e ) => BlockFaultTolerance::CHANNEL === ( $e['channel'] ?? '' ) ) );

		$threw = false;
		$result = null;
		try {
			$result = Publisher::publish( $page_id, 'page', self::corrupt_project_data(), '<div>ok</div>', '', get_current_user_id() );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		$after_count = count( array_filter( Diagnostics::recent( 500 ), fn( $e ) => BlockFaultTolerance::CHANNEL === ( $e['channel'] ?? '' ) ) );

		self::cleanup_posts( array( $page_id ) );

		$ok = ! $threw
			&& null !== $result
			&& ! empty( $result['quarantined'] )
			&& $after_count > $before_count;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'публикация с одним неизвестным блоком не бросила исключение, вернула отчёт о карантине и записала инцидент в диагностику'
				: 'ожидалась публикация без исключения + непустой quarantined + запись в Diagnostics',
			'result' => array( 'threw' => $threw, 'quarantined_count' => $result ? count( $result['quarantined'] ) : null ),
		);
	}

	private static function check_publish_survives_structural_corruption() {
		$page_id = self::make_page( 'Phase6 Structural Corruption Page' );

		$threw  = false;
		$result = null;
		try {
			$result = Publisher::publish( $page_id, 'page', self::structurally_broken_project_data(), '<div>ok</div>', '', get_current_user_id() );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		self::cleanup_posts( array( $page_id ) );

		$ok = ! $threw && null !== $result && ! empty( $result['quarantined'] );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'структурно испорченный узел (не массив среди components) убран без падения публикации'
				: 'ожидалась публикация без исключения даже при структурно испорченном узле',
			'result' => array( 'threw' => $threw ),
		);
	}

	private static function check_editor_load_quarantines_missing_block() {
		$page_id    = self::make_page( 'Phase6 Editor Load Page' );
		$repository = self::repository();

		$repository->save_draft( $page_id, 'page', self::corrupt_project_data(), null, get_current_user_id() );

		$loaded = $repository->get_for_edit( $page_id, 'page' );

		self::cleanup_posts( array( $page_id ) );

		$components = $loaded['project_data']['pages'][0]['frames'][0]['component']['components'] ?? array();
		$has_frozen_marker = false;
		foreach ( $components as $component ) {
			if ( ! empty( $component['attributes']['data-wpb-frozen-block'] ) ) {
				$has_frozen_marker = true;
			}
		}

		$ok = DocumentRepository::STATUS_OK === $loaded['status'] && $has_frozen_marker;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'документ с уже отсутствующим в библиотеке блоком открывается в редакторе (status=ok), проблемный узел заменён заглушкой'
				: 'ожидался status=ok + заглушка на месте отсутствующего блока',
			'result' => array( 'status' => $loaded['status'], 'has_frozen_marker' => $has_frozen_marker ),
		);
	}

	private static function project_data_with_block( string $block_id ): array {
		return array(
			'pages'  => array(
				array(
					'frames' => array(
						array(
							'component' => array(
								'type'       => 'wrapper',
								'components' => array(
									array(
										'type'       => 'div',
										'attributes' => array( 'data-wpb-block' => $block_id, 'data-wpb-values' => '{}' ),
										'components' => array(),
									),
								),
							),
						),
					),
				),
			),
			'styles' => array(),
		);
	}

	private static function check_page_assets_resolves_js_for_used_block() {
		$assets = PageAssets::resolve_js_assets( self::project_data_with_block( 'test-faq' ) );

		$has_accordion = false;
		foreach ( $assets as $asset ) {
			if ( 'test-faq' === ( $asset['block_id'] ?? null ) && false !== strpos( $asset['url'] ?? '', 'accordion.js' ) ) {
				$has_accordion = true;
			}
		}

		return array(
			'pass'   => $has_accordion,
			'detail' => $has_accordion
				? 'test-faq (requirements.assets.js=["accordion.js"]) резолвится в реальный URL accordion.js'
				: 'ожидался URL accordion.js для test-faq',
			'result' => array( 'assets' => $assets ),
		);
	}

	private static function check_page_assets_empty_for_block_without_js() {
		$assets = PageAssets::resolve_js_assets( self::project_data_with_block( 'test-cta' ) );

		$ok = empty( $assets );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'test-cta не объявляет requirements.assets.js — резолвер не подключает ни рантайм, ни JS этого блока'
				: 'ожидался пустой список JS-ассетов для блока без requirements.assets.js',
			'result' => array( 'assets' => $assets ),
		);
	}

	private static function check_publish_stores_small_page_css() {
		$page_id  = self::make_page( 'Phase6 Page CSS Split' );
		$override = '.phase6-e2e-marker { color: red; }';

		$result = Publisher::publish( $page_id, 'page', self::project_data_with_block( 'test-cta' ), '<div>ok</div>', $override, get_current_user_id() );

		$stored_css = get_post_meta( $page_id, Publisher::META_CSS, true );

		self::cleanup_posts( array( $page_id ) );

		$has_override    = false !== strpos( $stored_css, 'phase6-e2e-marker' );
		$lacks_block_css = false === strpos( $stored_css, '.wpb-block--cta' );
		$ok              = $has_override && $lacks_block_css;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? '_wpb_page_css содержит пользовательский override, но НЕ содержит базовый style.css блока (раздел 11: он теперь в общем файле /wpgjb/v1/blocks/style.css)'
				: 'ожидался маленький per-page CSS без базовых стилей блоков',
			'result' => array( 'stored_css_length' => strlen( $stored_css ), 'has_override' => $has_override, 'lacks_block_css' => $lacks_block_css ),
		);
	}

	private static function check_snapshot_matches_itself() {
		$snapshot = BlockSnapshotter::generate_snapshot( 'test-cta' );
		$result   = BlockSnapshotter::compare_snapshots( $snapshot, $snapshot );

		$ok = 'match' === $result['status'] && null === $result['diff'];

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'снимок test-cta, сравненный сам с собой, помечен как match (истинно-положительный случай)'
				: 'ожидался status=match при сравнении снимка с самим собой',
			'result' => $result,
		);
	}

	private static function check_snapshot_detects_mismatch() {
		$baseline = BlockSnapshotter::generate_snapshot( 'test-cta' );
		$mutated  = $baseline;
		// Симулируем непреднамеренное структурное изменение блока при
		// обновлении библиотеки — переименован data-slot в рендере.
		$mutated['rendered']['components'] = str_replace( 'data-slot=', 'data-slot-renamed=', $baseline['rendered']['components'] );

		$result = BlockSnapshotter::compare_snapshots( $baseline, $mutated );

		$ok = 'mismatch' === $result['status'] && isset( $result['diff']['rendered'] );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'намеренно испорченный снимок (переименован data-slot) корректно помечен как mismatch с диффом в rendered'
				: 'ожидался status=mismatch при структурном расхождении рендера',
			'result' => array( 'status' => $result['status'], 'has_rendered_diff' => isset( $result['diff']['rendered'] ) ),
		);
	}

	private static function check_snapshot_no_baseline() {
		$result = BlockSnapshotter::compare_snapshots( null, BlockSnapshotter::generate_snapshot( 'test-cta' ) );

		$ok = 'no_baseline' === $result['status'];

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'отсутствие сохранённого baseline честно репортится как no_baseline, не как ложный match/mismatch'
				: 'ожидался status=no_baseline при отсутствующем файле снимка',
			'result' => $result,
		);
	}
}
