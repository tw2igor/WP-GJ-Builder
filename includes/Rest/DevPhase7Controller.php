<?php

namespace WPGJBuilder\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для ручной проверки Phase 7 (приёмка MVP): фиксирует
 * находку независимого security review — ProjectDataSanitizer теперь
 * санитизирует узлы БЕЗ data-wpb-block, не только values внутри
 * платформенных блоков. Регистрируется только при WP_DEBUG=true.
 */
class DevPhase7Controller {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/phase7',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run_phase7' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run_phase7( \WP_REST_Request $request ) {
		require_once WPGJB_PLUGIN_DIR . 'tests/php/Phase7/Phase7Runner.php';

		$results  = \WPGJBuilder\Tests\Phase7\Phase7Runner::run();
		$all_pass = ! in_array( false, array_column( $results, 'pass' ), true );

		return new \WP_REST_Response(
			array(
				'verdict' => $all_pass ? 'PASS' : 'FAIL',
				'checks'  => $results,
			),
			200
		);
	}
}
