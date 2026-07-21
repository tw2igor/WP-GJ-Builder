<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\Blocks\BlockLibrary;
use WPGJBuilder\Blocks\BlockSnapshotter;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршруты для регрессионных снимков блоков (раздел 12 спеки).
 * /generate — снимки ВСЕХ текущих блоков (используется один раз для
 * создания начального baseline, коммитится в tests/php/Snapshots/blocks/).
 * /compare — сравнение текущего состояния библиотеки с закоммиченным
 * baseline; это и есть регрессионная проверка "релиз обновления
 * библиотеки блоков тестируется на сохранённых снимках". Гейт WP_DEBUG,
 * как остальные Dev*Controller.
 */
class DevSnapshotsController {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/snapshots/generate',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'generate' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wpgjb/v1',
			'/dev/snapshots/compare',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'compare' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function generate() {
		$snapshots = array();
		foreach ( array_keys( BlockLibrary::all() ) as $block_id ) {
			$snapshots[ $block_id ] = BlockSnapshotter::generate_snapshot( $block_id );
		}
		return new \WP_REST_Response( $snapshots );
	}

	public static function compare() {
		$report   = BlockSnapshotter::compare_all();
		$all_pass = ! in_array( 'mismatch', array_column( $report, 'status' ), true );

		return new \WP_REST_Response(
			array(
				'verdict' => $all_pass ? 'PASS' : 'FAIL',
				'report'  => $report,
			)
		);
	}
}
