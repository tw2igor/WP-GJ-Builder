<?php

namespace WPGJBuilder\Sanitize;

use WPGJBuilder\Blocks\BlockLibrary;
use WPGJBuilder\Core\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Контур 1 (раздел 10 спеки) для project_json: единственная реализация
 * санитизации значений слотов + CSS-правил внутри GrapesJS project data,
 * вызываемая при КАЖДОМ сохранении черновика (Phase 3). Строится ПОВЕРХ
 * уже проверенного Sanitizer (Spike 4) — не переопределяет его правила,
 * только применяет их к структуре project_data.
 *
 * Значения слотов живут в `data-wpb-values` (JSON) на корневом компоненте
 * каждого блока (см. assets/editor/blocks.js) — это и есть источник
 * правды, который здесь очищается. Любой компонент верхнего уровня,
 * заявленный как один из наших block-type (`data-wpb-block` есть), но БЕЗ
 * ожидаемого `data-wpb-values`, — подозрителен (обычный редактор всегда
 * его ставит) и обнуляется по умолчанию, а не доверяется как есть; это
 * защита от forged REST-вызова в обход обычного UI редактора.
 *
 * CSS-правила project_data.styles не привязаны структурно к конкретному
 * блоку (плоский список у GrapesJS) — очищаются общим whitelist
 * Sanitizer::CSS_PROPERTIES_PAGE (тем же, что использует контур 2 для
 * страницы целиком). Более узкое per-block ограничение по
 * style_whitelist на уровне ХРАНЕНИЯ — усиление для Phase 6; на уровне
 * РЕДАКТОРА оно уже есть через component.stylable (Style Manager UI).
 */
class ProjectDataSanitizer {

	public static function sanitize( array $project_data ): array {
		if ( ! empty( $project_data['pages'] ) && is_array( $project_data['pages'] ) ) {
			foreach ( $project_data['pages'] as &$page ) {
				if ( empty( $page['frames'] ) || ! is_array( $page['frames'] ) ) {
					continue;
				}
				foreach ( $page['frames'] as &$frame ) {
					if ( ! empty( $frame['component'] ) && is_array( $frame['component'] ) ) {
						self::sanitize_children( $frame['component'] );
					}
				}
				unset( $frame );
			}
			unset( $page );
		}

		if ( ! empty( $project_data['styles'] ) && is_array( $project_data['styles'] ) ) {
			foreach ( $project_data['styles'] as &$rule ) {
				if ( is_array( $rule ) && isset( $rule['style'] ) && is_array( $rule['style'] ) ) {
					$rule['style'] = self::sanitize_style_object( $rule['style'] );
				}
			}
			unset( $rule );
		}

		return $project_data;
	}

	private static function sanitize_children( array &$component ) {
		if ( empty( $component['components'] ) || ! is_array( $component['components'] ) ) {
			return;
		}
		foreach ( $component['components'] as &$child ) {
			if ( is_array( $child ) ) {
				self::sanitize_component( $child );
			}
		}
		unset( $child );
	}

	private static function sanitize_component( array &$component ) {
		$block_id = $component['attributes']['data-wpb-block'] ?? null;
		$block    = $block_id ? BlockLibrary::get( $block_id ) : null;

		if ( $block && isset( $component['attributes']['data-wpb-values'] ) ) {
			$values = json_decode( $component['attributes']['data-wpb-values'], true );
			if ( is_array( $values ) ) {
				$sanitized                                      = self::sanitize_values( $block['manifest']['slots'], $values );
				$component['attributes']['data-wpb-values'] = wp_json_encode( $sanitized );
			} else {
				$component['attributes']['data-wpb-values'] = wp_json_encode( array() );
			}
		} elseif ( null !== $block_id ) {
			// Заявлен как block-type, но без ожидаемого data-wpb-values —
			// обычный редактор так никогда не сохраняет; безопасный дефолт —
			// не доверять содержимому.
			$component['components'] = array();
		} else {
			// НЕ платформенный блок — обычный/нативный узел GrapesJS
			// (например, результат импорта/paste HTML в canvas — именно
			// вектор issue #3082 из адверсариального корпуса спайка 4).
			// До этого исправления (найдено независимым security review
			// Phase 7) такой узел вообще не проходил санитизацию —
			// проверялись только значения ВНУТРИ data-wpb-values, а
			// собственные attributes/tagName/content узла без
			// data-wpb-block пропускались целиком, включая onerror/onload
			// и javascript:-протоколы. Прогоняем через ТОТ ЖЕ Sanitizer,
			// что и весь остальной HTML — не вторая реализация правил.
			self::sanitize_generic_node( $component );
		}

		self::sanitize_children( $component );
	}

	/** Whitelist-санитизация СОБСТВЕННЫХ attributes/tagName/content узла без data-wpb-block. */
	private static function sanitize_generic_node( array &$component ) {
		$tag = self::safe_tag_name( is_string( $component['tagName'] ?? null ) ? $component['tagName'] : 'div' );

		$attr_html = '';
		if ( ! empty( $component['attributes'] ) && is_array( $component['attributes'] ) ) {
			foreach ( $component['attributes'] as $name => $value ) {
				if ( ! is_string( $name ) || ! is_scalar( $value ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9\-]*$/', $name ) ) {
					continue;
				}
				$attr_html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
			}
		}

		$content = is_string( $component['content'] ?? null ) ? $component['content'] : '';

		$html      = "<{$tag}{$attr_html}>{$content}</{$tag}>";
		$sanitized = Sanitizer::sanitize_html( $html, Sanitizer::richness_page() );

		$result = self::parse_sanitized_node( $sanitized, $tag );

		$component['tagName']    = $result['tag'];
		$component['attributes'] = $result['attributes'];
		if ( array_key_exists( 'content', $component ) ) {
			$component['content'] = $result['content'];
		}
	}

	/** Тег принудительно приводится к безопасному значению ДО прогона через wp_kses — не доверяем произвольному tagName при построении фрагмента. */
	private static function safe_tag_name( string $tag ): string {
		$tag     = strtolower( $tag );
		$allowed = array_keys( Sanitizer::richness_page() );
		return in_array( $tag, $allowed, true ) ? $tag : 'div';
	}

	/** @return array{tag: string, attributes: array<string,string>, content: string} */
	private static function parse_sanitized_node( string $html, string $fallback_tag ): array {
		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="wpb-sanitize-wrap">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();

		$wrap = $dom->getElementById( 'wpb-sanitize-wrap' );
		$el   = null;
		if ( $wrap ) {
			foreach ( $wrap->childNodes as $node ) {
				if ( XML_ELEMENT_NODE === $node->nodeType ) {
					$el = $node;
					break;
				}
			}
		}

		if ( ! $el ) {
			// wp_kses убрал корневой тег целиком (вне whitelist) — безопасный
			// дефолт: пустой узел без атрибутов/контента, дерево не падает.
			return array( 'tag' => $fallback_tag, 'attributes' => array(), 'content' => '' );
		}

		$attributes = array();
		foreach ( $el->attributes as $attr ) {
			$attributes[ $attr->nodeName ] = $attr->nodeValue;
		}

		$content = '';
		foreach ( $el->childNodes as $child ) {
			$content .= $dom->saveHTML( $child );
		}

		return array( 'tag' => $el->tagName, 'attributes' => $attributes, 'content' => $content );
	}

	/**
	 * Публичный, потому что переиспользуется вне контура 1: REST-эндпоинт
	 * вставки блока (Phase 4, "AI вставляет блок теми же операциями, что
	 * человек" — П7 спеки) обязан прогонять входящие значения слотов
	 * через ТУ ЖЕ логику, не заводить вторую реализацию правил.
	 */
	public static function sanitize_values( array $slots, array $values ): array {
		$sanitized = array();

		foreach ( $slots as $slot ) {
			$key = $slot['key'];
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];

			switch ( $slot['type'] ) {
				case 'richtext':
					$sanitized[ $key ] = is_string( $value )
						? Sanitizer::sanitize_html( $value, Sanitizer::richness_rich_text() )
						: '';
					break;

				case 'array':
					$item_slots = array();
					foreach ( $slot['item_schema'] as $item_key => $item_slot ) {
						$item_slot['key'] = $item_key;
						$item_slots[]     = $item_slot;
					}
					$items             = is_array( $value ) ? $value : array();
					$sanitized[ $key ] = array_map(
						function ( $item ) use ( $item_slots ) {
							return is_array( $item ) ? self::sanitize_values( $item_slots, $item ) : array();
						},
						$items
					);
					break;

				case 'image':
					$sanitized[ $key ] = ( is_scalar( $value ) && ctype_digit( (string) $value ) ) ? (string) $value : '';
					break;

				case 'link':
					$sanitized[ $key ] = is_string( $value ) ? esc_url_raw( $value ) : '';
					break;

				case 'raw_html':
					$sanitized[ $key ] = self::sanitize_raw_html_value( is_string( $value ) ? $value : '' );
					break;

				default: // string, icon — без разметки вовсе.
					$sanitized[ $key ] = is_string( $value )
						? Sanitizer::sanitize_html( $value, Sanitizer::richness_none() )
						: '';
					break;
			}
		}

		return $sanitized;
	}

	/**
	 * Раздел 10: "право на произвольный HTML/JS-код — отдельный флаг,
	 * по умолчанию выключен". Единственное место во всём контуре 1, где
	 * значение слота может пройти БЕЗ санитизации — и только если ТЕКУЩИЙ
	 * пользователь (на момент сохранения) реально обладает капабилити
	 * `wpgjb_insert_raw_code`; иначе — тот же безопасный дефолт
	 * "ноль тегов", что и у обычных строковых слотов. Каждый пропуск без
	 * санитизации пишется в аудит-журнал (раздел 10: "Аудит-лог: кто и
	 * когда вставил произвольный код").
	 */
	private static function sanitize_raw_html_value( string $value ): string {
		if ( ! current_user_can( 'wpgjb_insert_raw_code' ) ) {
			return Sanitizer::sanitize_html( $value, Sanitizer::richness_none() );
		}

		Diagnostics::log(
			'raw-code-audit',
			sprintf( 'Пользователь #%d сохранил блок "Вставка кода" без санитизации.', get_current_user_id() ),
			array(
				'user_id'       => get_current_user_id(),
				'snippet_hash'  => md5( $value ),
				'snippet_length' => strlen( $value ),
			)
		);

		return $value;
	}

	private static function sanitize_style_object( array $style ): array {
		$declaration = '';
		foreach ( $style as $prop => $value ) {
			if ( ! is_string( $prop ) || ! is_scalar( $value ) ) {
				continue;
			}
			$declaration .= $prop . ': ' . $value . ';';
		}

		$sanitized_css = Sanitizer::sanitize_css( $declaration, Sanitizer::CSS_PROPERTIES_PAGE );

		$result = array();
		foreach ( explode( ';', $sanitized_css ) as $decl ) {
			$decl = trim( $decl );
			if ( '' === $decl ) {
				continue;
			}
			$pos = strpos( $decl, ':' );
			if ( false === $pos ) {
				continue;
			}
			$prop           = trim( substr( $decl, 0, $pos ) );
			$result[ $prop ] = trim( substr( $decl, $pos + 1 ) );
		}

		return $result;
	}
}
