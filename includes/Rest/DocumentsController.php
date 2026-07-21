<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\Storage\DocumentRepository;
use WPGJBuilder\Storage\MigrationRunner;
use WPGJBuilder\Sanitize\ProjectDataSanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * REST: загрузка документа для редактирования и сохранение черновика
 * (раздел 3 спеки, план Phase 1 — "CRUD-слой + REST save/load project_json,
 * капабилити-гейт, без рендера"). Публикация (запись в post_content,
 * генерация статического HTML) — отдельный контроллер в Phase 2, здесь
 * её сознательно нет.
 *
 * Контур 1 (раздел 10, Phase 3): каждое сохранение прогоняется через
 * ProjectDataSanitizer ДО записи — та же логика, что использует контур 2
 * при публикации (Sanitizer), применённая к структуре project_data.
 */
class DocumentsController {

	const NAMESPACE_ = 'wpgjb/v1';

	const ALLOWED_TYPES = array( 'page', 'header', 'footer', 'sidebar', 'block', 'template' );

	public static function register_routes() {
		$args_common = array(
			'post_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'type'    => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => self::ALLOWED_TYPES,
			),
		);

		register_rest_route(
			self::NAMESPACE_,
			'/documents/(?P<post_id>\d+)/(?P<type>[a-z]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'get_document' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => $args_common,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'save_document' ),
					'permission_callback' => array( self::class, 'check_permission' ),
					'args'                => array_merge(
						$args_common,
						array(
							'project_data' => array(
								'type'     => 'object',
								'required' => true,
							),
							'doc_version'  => array(
								'type'     => 'integer',
								'required' => false,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/documents/(?P<post_id>\d+)/(?P<type>[a-z]+)/page-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_page_settings' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array_merge(
					$args_common,
					array(
						'title'         => array( 'type' => 'string', 'required' => true ),
						'slug'          => array( 'type' => 'string', 'required' => false ),
						'status'        => array( 'type' => 'string', 'required' => false, 'enum' => array( 'draft', 'publish' ) ),
						'featured_media' => array( 'type' => 'integer', 'required' => false ),
						'page_template' => array( 'type' => 'string', 'required' => false ),
					)
				),
			)
		);
	}

	/**
	 * Капабилити-гейт (раздел 10 спеки): общая возможность работать с
	 * конструктором (`wpgjb_edit_pages`) плюс штатное право WP на
	 * редактирование конкретной записи — не подменяем систему прав WP,
	 * добавляем поверх неё.
	 */
	public static function check_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'wpgjb_edit_pages' ) ) {
			return new \WP_Error(
				'wpgjb_forbidden',
				__( 'Недостаточно прав для работы с конструктором.', 'wp-gj-builder' ),
				array( 'status' => 403 )
			);
		}

		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error(
				'wpgjb_not_found',
				__( 'Запись не найдена.', 'wp-gj-builder' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'wpgjb_forbidden',
				__( 'Нет прав на редактирование этой записи.', 'wp-gj-builder' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	private static function repository() {
		return new DocumentRepository( new MigrationRunner() );
	}

	public static function get_document( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$type    = $request->get_param( 'type' );

		$result = self::repository()->get_for_edit( $post_id, $type );

		if ( DocumentRepository::STATUS_FROZEN === $result['status'] ) {
			// Раздел 12: неудача миграции/повреждённый JSON не должны быть
			// 5xx — это ожидаемое, корректно обработанное состояние, о
			// котором клиент (редактор) должен показать понятное сообщение.
			return new \WP_REST_Response(
				array(
					'status'      => 'frozen',
					'error'       => $result['error'],
					'doc_version' => $result['doc_version'],
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'status'       => 'ok',
				'project_data' => $result['project_data'],
				'doc_version'  => $result['doc_version'],
			),
			200
		);
	}

	public static function save_page_settings( \WP_REST_Request $request ) {
		$post_id        = (int) $request->get_param( 'post_id' );
		$title          = (string) $request->get_param( 'title' );
		$slug           = $request->get_param( 'slug' );
		$status         = $request->get_param( 'status' );
		$featured_media = $request->get_param( 'featured_media' );
		$page_template  = $request->get_param( 'page_template' );

		$post_args = array(
			'ID'         => $post_id,
			'post_title' => $title,
		);
		if ( null !== $slug && '' !== $slug ) {
			$post_args['post_name'] = sanitize_title( $slug );
		}
		if ( null !== $status ) {
			$post_args['post_status'] = $status;
		}

		$updated = wp_update_post( $post_args, true );

		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( 'wpgjb_save_failed', __( 'Не удалось сохранить настройки страницы.', 'wp-gj-builder' ), array( 'status' => 500 ) );
		}

		if ( null !== $page_template ) {
			update_post_meta( $post_id, '_wp_page_template', (string) $page_template );
		}

		if ( null !== $featured_media ) {
			$featured_media = (int) $featured_media;
			if ( $featured_media > 0 ) {
				set_post_thumbnail( $post_id, $featured_media );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		$post = get_post( $post_id );

		return new \WP_REST_Response(
			array(
				'status'            => 'ok',
				'title'             => get_the_title( $post_id ),
				'slug'              => $post->post_name,
				'post_status'       => $post->post_status,
				'featured_media'    => (int) get_post_thumbnail_id( $post_id ),
				'featured_media_url' => get_the_post_thumbnail_url( $post_id, 'thumbnail' ) ?: '',
				'page_template'     => get_post_meta( $post_id, '_wp_page_template', true ),
			),
			200
		);
	}

	public static function save_document( \WP_REST_Request $request ) {
		$post_id              = (int) $request->get_param( 'post_id' );
		$type                 = $request->get_param( 'type' );
		$project_data         = (array) $request->get_param( 'project_data' );
		$expected_doc_version = $request->get_param( 'doc_version' );
		$expected_doc_version = null === $expected_doc_version ? null : (int) $expected_doc_version;

		$project_data = ProjectDataSanitizer::sanitize( $project_data );

		$result = self::repository()->save_draft(
			$post_id,
			$type,
			$project_data,
			$expected_doc_version,
			get_current_user_id()
		);

		if ( DocumentRepository::STATUS_CONFLICT === $result['status'] ) {
			// Раздел 5.5: конфликт сохранения — предложить сравнение, не
			// перезаписывать молча. 409, не 200 — клиент обязан это заметить.
			$server_project_data = null;
			if ( ! empty( $result['server_row']['project_json'] ) ) {
				$decoded             = json_decode( $result['server_row']['project_json'], true );
				$server_project_data = is_array( $decoded ) ? $decoded : null;
			}

			return new \WP_REST_Response(
				array(
					'status'              => 'conflict',
					'doc_version'         => $result['doc_version'],
					'server_project_data' => $server_project_data,
				),
				409
			);
		}

		return new \WP_REST_Response(
			array(
				'status'      => 'ok',
				'doc_version' => $result['doc_version'],
			),
			200
		);
	}
}
