<?php

namespace WPGJBuilder\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для ручной проверки Phase 6a (надёжность + очистка).
 * Регистрируется только при WP_DEBUG=true. Удалить перед Phase 7.
 */
class DevPhase6Controller {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/phase6',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run_phase6' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run_phase6( \WP_REST_Request $request ) {
		require_once WPGJB_PLUGIN_DIR . 'tests/php/Phase6/Phase6Runner.php';

		$results  = \WPGJBuilder\Tests\Phase6\Phase6Runner::run();
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
