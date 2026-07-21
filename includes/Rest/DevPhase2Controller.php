<?php

namespace WPGJBuilder\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для ручной проверки Phase 2 (publish-пайплайн: статический
 * HTML, санитизация, динамические теги на фронтенде) через curl/браузер.
 * Регистрируется только при WP_DEBUG=true. Удалить перед Phase 7.
 */
class DevPhase2Controller {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/phase2',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run_phase2' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run_phase2( \WP_REST_Request $request ) {
		require_once WPGJB_PLUGIN_DIR . 'tests/php/Phase2/Phase2Runner.php';

		$results  = \WPGJBuilder\Tests\Phase2\Phase2Runner::run();
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
