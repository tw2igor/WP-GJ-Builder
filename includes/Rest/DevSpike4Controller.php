<?php

namespace WPGJBuilder\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для ручной проверки Phase 0 Спайка 4 (санитизация)
 * через curl/браузер, пока в среде разработки нет PHPUnit/WP-CLI.
 * Регистрируется только при WP_DEBUG=true — ТРЕБУЕТСЯ ОДНА СТРОКА В
 * WPGJBuilder\Core\Plugin::boot() (не добавлена этим файлом намеренно —
 * Core/ трогает координирующая сессия):
 *
 *   add_action( 'rest_api_init', array( DevSpike4Controller::class, 'register_routes' ) );
 *
 * (по аналогии с уже существующей регистрацией DevSpikeController там же).
 * Должен быть удалён к Phase 7 (приёмка MVP), см. план: "Критические файлы".
 */
class DevSpike4Controller {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/spike4',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run_spike4' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run_spike4( \WP_REST_Request $request ) {
		require_once WPGJB_PLUGIN_DIR . 'tests/php/Spikes/Spike4Runner.php';

		$results  = \WPGJBuilder\Tests\Spikes\Spike4Runner::run();
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
