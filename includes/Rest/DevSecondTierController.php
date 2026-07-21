<?php

namespace WPGJBuilder\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для второй очереди (после MVP): сайдбары как третий
 * тип части сайта, ACF-теги в реестре динамических тегов. Гейт WP_DEBUG,
 * как остальные Dev*Controller.
 */
class DevSecondTierController {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/second-tier',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run( \WP_REST_Request $request ) {
		require_once WPGJB_PLUGIN_DIR . 'tests/php/SecondTier/SecondTierRunner.php';

		$results  = \WPGJBuilder\Tests\SecondTier\SecondTierRunner::run();
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
