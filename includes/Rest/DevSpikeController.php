<?php

namespace WPGJBuilder\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для ручной проверки Phase 0 спайков через curl/браузер,
 * пока в среде разработки нет PHPUnit/WP-CLI. Регистрируется только при
 * WP_DEBUG=true (см. Plugin::boot()) и должен быть удалён к Phase 7.
 */
class DevSpikeController {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/spike3',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run_spike3' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run_spike3( \WP_REST_Request $request ) {
		require_once WPGJB_PLUGIN_DIR . 'tests/php/Spikes/Spike3Runner.php';

		$results = \WPGJBuilder\Tests\Spikes\Spike3Runner::run();
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
