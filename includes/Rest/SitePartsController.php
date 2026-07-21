<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\SiteParts\DisplayConditions;
use WPGJBuilder\SiteParts\PartsPostType;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD частей сайта + их условий отображения (раздел 7 спеки). Сам
 * контент части (project_json/HTML) идёт через УЖЕ существующие
 * DocumentsController/PublishController (type=header|footer) — здесь
 * только то, что специфично для частей: список, создание записи-
 * контейнера, условия отображения.
 */
class SitePartsController {

	const ALLOWED_PART_TYPES = PartsPostType::PART_TYPES;

	public static function register_routes() {
		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/parts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'list_parts' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'type' => array( 'type' => 'string', 'required' => true, 'enum' => self::ALLOWED_PART_TYPES ),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'create_part' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array(
						'type'  => array( 'type' => 'string', 'required' => true, 'enum' => self::ALLOWED_PART_TYPES ),
						'title' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/parts/(?P<post_id>\d+)/conditions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'get_conditions' ),
					'permission_callback' => array( DocumentsController::class, 'check_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'set_conditions' ),
					'permission_callback' => array( DocumentsController::class, 'check_permission' ),
					'args'                => array(
						'conditions' => array( 'type' => 'array', 'required' => true ),
					),
				),
			)
		);
	}

	public static function check_permission() {
		return current_user_can( 'wpgjb_edit_pages' );
	}

	public static function list_parts( \WP_REST_Request $request ) {
		$type  = $request->get_param( 'type' );
		$parts = array();

		foreach ( PartsPostType::list_by_type( $type ) as $post ) {
			$parts[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'modified'   => $post->post_modified,
				'conditions' => PartsPostType::get_conditions( $post->ID ),
			);
		}

		return new \WP_REST_Response( array( 'parts' => $parts ) );
	}

	public static function create_part( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'wpgjb_forbidden', __( 'Недостаточно прав для создания части сайта.', 'wp-gj-builder' ), array( 'status' => 403 ) );
		}

		$type  = $request->get_param( 'type' );
		$title = $request->get_param( 'title' );

		$post_id = PartsPostType::create( $type, $title, get_current_user_id() );

		return new \WP_REST_Response( array( 'id' => $post_id ), 201 );
	}

	public static function get_conditions( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'post_id' );
		return new \WP_REST_Response( array( 'conditions' => PartsPostType::get_conditions( $id ) ) );
	}

	public static function set_conditions( \WP_REST_Request $request ) {
		$id         = (int) $request->get_param( 'post_id' );
		$conditions = (array) $request->get_param( 'conditions' );

		foreach ( $conditions as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['scope'] ) || empty( $rule['mode'] ) ) {
				return new \WP_Error( 'wpgjb_invalid_condition', __( 'Каждое условие должно содержать scope и mode.', 'wp-gj-builder' ), array( 'status' => 422 ) );
			}
			if ( ! in_array( $rule['mode'], array( 'include', 'exclude' ), true ) ) {
				return new \WP_Error( 'wpgjb_invalid_condition', __( 'mode должен быть include или exclude.', 'wp-gj-builder' ), array( 'status' => 422 ) );
			}
		}

		PartsPostType::set_conditions( $id, $conditions );

		return new \WP_REST_Response( array( 'status' => 'ok', 'conditions' => $conditions ) );
	}
}
