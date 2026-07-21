<?php

namespace WPGJBuilder\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Серверная (PHP) реализация того же рендера, что assets/editor/blocks.js
 * делает на клиенте: применяет значения слотов к markup-шаблону блока и
 * возвращает GrapesJS-совместимый узел компонента. Нужна для REST
 * вставки блока (Phase 4, раздел 9: "AI вставляет блок теми же
 * операциями, что доступны человеку" — П7) — без этого AI/внешний REST-
 * клиент не может добавить блок в документ, не зная GrapesJS.
 *
 * `components` в возвращаемом узле — СТРОКА (внутренний HTML), не массив
 * дочерних узлов: GrapesJS одинаково парсит HTML-строку через Component
 * Recognition и при `component.components(html)` в реальном времени
 * (см. blocks.js), и при `editor.loadProjectData()` — то же самое
 * представление, которое клиент сам создаёт при вставке блока через "+".
 * Оба пути (человек через "+" и AI через REST) дают идентичную форму
 * project_data — не два разных формата одного и того же.
 */
class BlockRenderer {

	/**
	 * @return array{type: string, attributes: array<string,string>, components: string}
	 */
	public static function render_component( string $block_id, array $manifest, string $markup, array $values ): array {
		$dom  = self::parse_fragment( $markup );
		$root = self::find_root_element( $dom );

		if ( ! $root ) {
			return array(
				'type'       => $block_id,
				'attributes' => array(
					'data-wpb-block'  => $block_id,
					'data-wpb-values' => wp_json_encode( $values ),
				),
				'components' => '',
			);
		}

		self::apply_values( $dom, $root, $manifest['slots'], $values );

		// Атрибуты КОРНЕВОГО элемента шаблона (в первую очередь class —
		// им подключается style.css блока) должны сохраниться в
		// компоненте, как и при клиентской вставке через "+" (там
		// blockEl.outerHTML целиком несёт исходные атрибуты корня —
		// см. blocks.js renderBlockElement). Без этого блок, вставленный
		// через REST, визуально ничем не оформлен (найдено реальным
		// прогоном: раунд-трип показал текст без стилей).
		$attributes = self::element_attributes( $root );
		$attributes['data-wpb-block']  = $block_id;
		$attributes['data-wpb-values'] = wp_json_encode( $values );

		return array(
			'type'       => $block_id,
			'attributes' => $attributes,
			'components' => self::inner_html( $dom, $root ),
		);
	}

	private static function element_attributes( \DOMElement $el ): array {
		$attributes = array();
		foreach ( $el->attributes as $attr ) {
			$attributes[ $attr->nodeName ] = $attr->nodeValue;
		}
		return $attributes;
	}

	private static function parse_fragment( string $html ): \DOMDocument {
		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		// Префикс с XML-декларацией — стандартный приём, чтобы DOMDocument
		// трактовал вход как UTF-8 без добавления видимого <meta> в вывод.
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="wpb-root-wrap">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		return $dom;
	}

	private static function find_root_element( \DOMDocument $dom ) {
		$wrap = $dom->getElementById( 'wpb-root-wrap' );
		if ( ! $wrap ) {
			return null;
		}
		foreach ( $wrap->childNodes as $node ) {
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				return $node;
			}
		}
		return null;
	}

	private static function inner_html( \DOMDocument $dom, \DOMElement $el ): string {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}

	private static function apply_values( \DOMDocument $dom, \DOMElement $scope, array $slots, array $values ) {
		$xpath = new \DOMXPath( $dom );

		foreach ( $slots as $slot ) {
			$key = $slot['key'];

			if ( 'array' === $slot['type'] ) {
				$repeat = self::find_own_scope( $xpath, $scope, sprintf( '@data-slot-repeat="%s"', $key ) );
				if ( ! $repeat ) {
					continue;
				}
				$item_template = self::first_descendant_with_attr( $xpath, $repeat, 'data-slot-item' );
				if ( ! $item_template ) {
					continue;
				}

				$item_slots = array();
				foreach ( $slot['item_schema'] as $item_key => $item_slot ) {
					$item_slot['key'] = $item_key;
					$item_slots[]      = $item_slot;
				}

				$items = is_array( $values[ $key ] ?? null ) ? $values[ $key ] : array();

				// Снять текущих детей repeat-контейнера, оставив шаблон нетронутым
				// (клонируем ДО удаления).
				$template_clone = $item_template->cloneNode( true );
				while ( $repeat->firstChild ) {
					$repeat->removeChild( $repeat->firstChild );
				}

				foreach ( $items as $item_values ) {
					$clone = $template_clone->cloneNode( true );
					$repeat->appendChild( $clone );
					if ( is_array( $item_values ) ) {
						self::apply_values( $dom, $clone, $item_slots, $item_values );
					}
				}
				continue;
			}

			if ( 'link' === $slot['type'] ) {
				$link_el = self::find_own_scope( $xpath, $scope, sprintf( '@data-slot-href="%s"', $key ) );
				if ( $link_el ) {
					$link_el->setAttribute( 'href', $values[ $key ] ?? '#' );
				}
				continue;
			}

			$el = self::find_own_scope( $xpath, $scope, sprintf( '@data-slot="%s"', $key ) );
			if ( ! $el ) {
				continue;
			}

			$value = (string) ( $values[ $key ] ?? '' );

			if ( 'image' === $slot['type'] ) {
				$el->setAttribute( 'data-slot-img-id', $value );
				self::apply_image_attributes( $el, $value, ! empty( $slot['first_screen'] ) );
			} elseif ( 'richtext' === $slot['type'] ) {
				self::set_inner_html( $dom, $el, $value );
			} else {
				self::set_text( $el, $value );
			}
		}
	}

	/**
	 * Раздел 11 спеки: реальное разрешение image-слота (ID вложения ->
	 * src/srcset/sizes/alt) для серверного (REST/AI) пути вставки блока —
	 * зеркало клиентской логики images.js, только через штатные функции
	 * WP core вместо ручного REST-парсинга. `wp_calculate_image_srcset()`/
	 * `wp_calculate_image_sizes()` — та же пара, которую использует само
	 * ядро внутри `wp_get_attachment_image()`.
	 */
	private static function apply_image_attributes( \DOMElement $el, string $value, bool $first_screen ) {
		$attachment_id = absint( $value );
		if ( ! $attachment_id ) {
			$el->removeAttribute( 'src' );
			$el->removeAttribute( 'srcset' );
			$el->removeAttribute( 'sizes' );
			return;
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'large' );
		if ( ! $src ) {
			return;
		}
		list( $src_url, $width, $height ) = $src;

		$el->setAttribute( 'src', $src_url );

		$image_meta = wp_get_attachment_metadata( $attachment_id );
		if ( $image_meta ) {
			$srcset = wp_calculate_image_srcset( array( $width, $height ), $src_url, $image_meta, $attachment_id );
			$sizes  = wp_calculate_image_sizes( array( $width, $height ), $src_url, $image_meta, $attachment_id );
			if ( $srcset ) {
				$el->setAttribute( 'srcset', $srcset );
				$el->setAttribute( 'sizes', $sizes );
			}
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( $alt ) {
			$el->setAttribute( 'alt', $alt );
		}

		if ( $first_screen ) {
			$el->setAttribute( 'fetchpriority', 'high' );
			$el->removeAttribute( 'loading' );
		} else {
			$el->setAttribute( 'loading', 'lazy' );
			$el->removeAttribute( 'fetchpriority' );
		}
	}

	/**
	 * Как DOMXPath::query() внутри $scope, но не заходит в найденных
	 * результатах внутрь вложенных [data-slot-repeat] (их слоты
	 * обрабатываются отдельно, per-item) — эквивалент findOwnScope() в
	 * assets/editor/blocks.js.
	 */
	private static function find_own_scope( \DOMXPath $xpath, \DOMElement $scope, string $attr_predicate ) {
		$candidates = $xpath->query( './/*[' . $attr_predicate . ']', $scope );
		foreach ( $candidates as $candidate ) {
			$node    = $candidate->parentNode;
			$nested  = false;
			while ( $node && $node !== $scope ) {
				if ( $node instanceof \DOMElement && $node->hasAttribute( 'data-slot-repeat' ) ) {
					$nested = true;
					break;
				}
				$node = $node->parentNode;
			}
			if ( ! $nested ) {
				return $candidate;
			}
		}
		return null;
	}

	private static function first_descendant_with_attr( \DOMXPath $xpath, \DOMElement $scope, string $attr ) {
		$nodes = $xpath->query( './/*[@' . $attr . ']', $scope );
		return $nodes->length ? $nodes->item( 0 ) : null;
	}

	private static function set_text( \DOMElement $el, string $text ) {
		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}
		$el->appendChild( $el->ownerDocument->createTextNode( $text ) );
	}

	private static function set_inner_html( \DOMDocument $dom, \DOMElement $el, string $html ) {
		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}
		$fragment_doc = self::parse_fragment( $html );
		$wrap         = self::find_root_element( $fragment_doc ) ? $fragment_doc->getElementById( 'wpb-root-wrap' ) : null;
		if ( ! $wrap ) {
			self::set_text( $el, $html );
			return;
		}
		foreach ( $wrap->childNodes as $child ) {
			$imported = $dom->importNode( $child, true );
			$el->appendChild( $imported );
		}
	}
}
