<?php

namespace WPGJBuilder\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для ручной проверки Phase 0 спайка 2 (батч-резолвер
 * динамических тегов) через curl/браузер, пока в среде разработки нет
 * PHPUnit/WP-CLI. Регистрируется только при WP_DEBUG=true (см.
 * Plugin::boot()) и должен быть удалён к Phase 7.
 *
 * Не подключён в Plugin::boot() этим же изменением намеренно — см.
 * отчёт спайка 2: координирующая сессия добавляет туда одну строку
 * add_action(), чтобы не конфликтовать с параллельной работой в Core/.
 */
class DevSpike2Controller {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/spike2',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run_spike2' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run_spike2( \WP_REST_Request $request ) {
		require_once WPGJB_PLUGIN_DIR . 'tests/php/Spikes/Spike2Runner.php';

		$results  = \WPGJBuilder\Tests\Spikes\Spike2Runner::run();
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
