<?php
/**
 * Спайк 4 (план Phase 0, раздел 10 спеки): единственный источник правил
 * whitelist-санитизации HTML/CSS. ОБА контура безопасности — контур
 * редактирования (сохранение) и контур публикации — обязаны вызывать
 * ИМЕННО эти статические методы; различаться должна только точка вызова,
 * не правила (см. план: "оба контура безопасности обязаны вызывать одну и
 * ту же логику, различаться должна только точка вызова, не правила").
 *
 * HTML: тонкая обёртка над wp_kses() ядра WP. `<script>` и `on*`-атрибуты
 * отклоняются просто фактом отсутствия в allowed_tags — это штатное
 * поведение wp_kses, здесь нет отдельной логики их отлова.
 *
 * CSS: ============================ ВАЖНО (СПАЙК-КАЧЕСТВО) ============================
 * У ядра WordPress нет встроенного CSS-санитайзера. План рекомендует
 * `sabberworm/php-css-parser` через Composer, но в этой песочнице
 * разработки НЕТ ни PHP CLI, ни Composer (проверено — оба бинаря
 * отсутствуют), поэтому реально навендорить и протестировать эту
 * библиотеку здесь невозможно. sanitize_css() ниже — САМОПИСНЫЙ,
 * ТОКЕНИЗАТОРНО/REGEX-ОСНОВАННЫЙ ВАЛИДАТОР, написанный для ЭТОГО СПАЙКА.
 * Он ловит все паттерны из адверсариального корпуса (tests/php/Spikes/
 * Spike4Runner.php), но НЕ является полноценным CSS-парсером и НЕ должен
 * рассматриваться как production-грade решение: он не понимает
 * полноценную грамматику CSS (вложенные @-правила, экранирование,
 * составные селекторы за пределами простого whitelist символов и т.п.)
 * и может иметь необнаруженные обходы за пределами протестированного
 * корпуса. К Phase 6 (производственное укрепление) эта реализация
 * ДОЛЖНА быть заменена на парсер-based решение (например,
 * sabberworm/php-css-parser) в реальном окружении с PHP+Composer.
 * =====================================================================
 */

namespace WPGJBuilder\Sanitize;

defined( 'ABSPATH' ) || exit;

class Sanitizer {

	/**
	 * Базовый набор CSS-свойств для демонстрации/тестов спайка —
	 * достаточно узкий "стилевой" whitelist (цвет, типографика,
	 * отступы), какой можно ожидать от per-block style_whitelist
	 * (раздел 9 спеки) или общего Zero-режима.
	 */
	const CSS_PROPERTIES_BASIC = array(
		'color',
		'background-color',
		'font-size',
		'font-weight',
		'text-align',
		'margin',
		'margin-top',
		'margin-right',
		'margin-bottom',
		'margin-left',
		'padding',
		'padding-top',
		'padding-right',
		'padding-bottom',
		'padding-left',
		'border-radius',
		'line-height',
	);

	/**
	 * Более широкий whitelist для санитизации ЦЕЛОЙ ОПУБЛИКОВАННОЙ страницы
	 * (Phase 2, контур 2 перед записью в post_content) — per-block
	 * style_whitelist (раздел 9) сузит это до конкретного блока в Phase 4;
	 * здесь же нужен разумный общий набор свойств семантической разметки
	 * страницы. Значения по-прежнему проходят через value_shape_ok() и
	 * CSS_FORBIDDEN_PATTERNS ниже — расширение списка имён свойств не
	 * ослабляет проверку самих значений.
	 */
	const CSS_PROPERTIES_PAGE = array(
		'color',
		'background-color',
		'background',
		'font-size',
		'font-weight',
		'font-family',
		'font-style',
		'text-align',
		'text-decoration',
		'line-height',
		'letter-spacing',
		'margin',
		'margin-top',
		'margin-right',
		'margin-bottom',
		'margin-left',
		'padding',
		'padding-top',
		'padding-right',
		'padding-bottom',
		'padding-left',
		'border-radius',
		'border',
		'border-color',
		'border-width',
		'border-style',
		'display',
		'position',
		'top',
		'right',
		'bottom',
		'left',
		'width',
		'height',
		'min-width',
		'max-width',
		'min-height',
		'max-height',
		'flex-direction',
		'justify-content',
		'align-items',
		'gap',
		'overflow',
		'opacity',
		'z-index',
		'cursor',
		'box-shadow',
	);

	/** Явно запрещённые легаси-векторы инъекции CSS (раздел 5.3 builder-analysis.md). */
	const CSS_FORBIDDEN_PATTERNS = array(
		'/expression\s*\(/i',
		'/-moz-binding/i',
		'/behavior\s*:/i',
		'/@import/i',
		'/javascript\s*:/i',
		'/vbscript\s*:/i',
	);

	/**
	 * Слот без inline-разметки (большинство контентных слотов, раздел 10:
	 * "большинство слотов — ноль тегов"). Весь HTML экранируется как текст.
	 */
	public static function richness_none(): array {
		return array();
	}

	/**
	 * Rich-text слот: малый inline-набор, достаточный для абзаца с акцентами
	 * и ссылкой, без какой-либо возможности исполнения кода.
	 */
	public static function richness_rich_text(): array {
		return array(
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			),
		);
	}

	/**
	 * Слот изображения: единственный тег <img> с безопасным набором
	 * атрибутов. `onload` и любые другие `on*`-атрибуты сюда НЕ добавлены —
	 * это прямая защита от паттерна issue #3082 (XSS в Live Preview через
	 * импорт HTML с onload на <img>).
	 */
	public static function richness_image(): array {
		return array(
			'img' => array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
				'class'  => true,
			),
		);
	}

	/**
	 * Whitelist ЦЕЛОЙ ОПУБЛИКОВАННОЙ страницы (Phase 2) — семантические
	 * блочные/инлайн-теги, которые реально производит конструктор, плюс
	 * базовый набор ARIA-атрибутов для доступности (раздел 13 спеки).
	 * wp_kses() не поддерживает wildcard-имена атрибутов (`data-*`) —
	 * только точные ключи, поэтому список конечен, а не префиксный.
	 * `<script>` и любые `on*`-атрибуты сюда НЕ включены ни для одного
	 * тега — это и есть их отклонение (см. sanitize_html()).
	 */
	public static function richness_page(): array {
		$common_attrs = array(
			'class'           => true,
			'id'              => true,
			'style'           => true,
			'title'           => true,
			'role'            => true,
			'aria-label'      => true,
			'aria-hidden'     => true,
			'aria-labelledby' => true,
			'aria-describedby' => true,
			'aria-expanded'   => true,
			'aria-selected'   => true,
			'tabindex'        => true,
			'hidden'          => true,
			// "Элементы" интерактивного/анимационного подраздела (countdown/
			// counter/gallery/slider, см. elements-interactive.js) несут
			// состояние ИСКЛЮЧИТЕЛЬНО в data-wpgjb-* атрибутах — рантайм
			// (assets/runtime/wpgjb-elements-runtime.js) читает их на
			// опубликованном фронтенде. wp_kses не поддерживает wildcard
			// `data-*`, поэтому каждый атрибут перечислен явно (см. также
			// PageAssets::INTERACTIVE_MARKER_ATTRIBUTES — тот же список
			// маркеров, используется для решения "нужен ли рантайм-скрипт").
			'data-wpgjb-countdown'        => true,
			'data-wpgjb-countdown-target' => true,
			'data-wpgjb-countdown-unit'   => true,
			'data-wpgjb-counter'          => true,
			'data-wpgjb-counter-target'   => true,
			'data-wpgjb-counter-duration' => true,
			'data-wpgjb-counter-value'    => true,
			'data-wpgjb-gallery'          => true,
			'data-wpgjb-gallery-item'     => true,
			'data-wpgjb-slider'           => true,
			'data-wpgjb-slider-index'     => true,
			'data-wpgjb-slider-track'     => true,
			'data-wpgjb-slider-prev'      => true,
			'data-wpgjb-slider-next'      => true,
			'data-wpgjb-slider-dots'      => true,
			'data-wpgjb-slider-dot'       => true,
			'data-wpgjb-slide'            => true,
			'data-index'                  => true,
			'data-wpgjb-chart'                => true,
			'data-wpgjb-datatable'            => true,
			'data-wpgjb-datatable-thead'      => true,
			'data-wpgjb-datatable-tbody'      => true,
			'data-wpgjb-sort-indicator'       => true,
			'aria-sort'                       => true,
			'scope'                           => true,
			'data-wpgjb-tabs'                 => true,
			'data-wpgjb-tabs-nav'             => true,
			'data-wpgjb-tabs-panels'          => true,
			'data-wpgjb-tab-nav'              => true,
			'data-wpgjb-tab-panel'            => true,
			'data-wpgjb-flipcard'             => true,
			'data-wpgjb-flipcard-inner'       => true,
			'data-wpgjb-hotspot'              => true,
			'data-wpgjb-hotspot-dot'          => true,
			'data-wpgjb-hotspot-tip'          => true,
			'data-wpgjb-codeblock'            => true,
			'data-wpgjb-codeblock-copy'       => true,
			'data-wpgjb-codeblock-copied-label' => true,
			'data-wpgjb-codeblock-code'       => true,
		);

		$block_tags = array( 'div', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside', 'figure', 'figcaption', 'ul', 'ol', 'li', 'details', 'table', 'thead', 'tbody', 'tr', 'blockquote', 'pre' );
		$inline_tags = array( 'span', 'strong', 'em', 'b', 'i', 'u', 'small', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'summary', 'label', 'cite', 'code' );

		$tags = array();
		foreach ( array_merge( $block_tags, $inline_tags ) as $tag ) {
			$tags[ $tag ] = $common_attrs;
		}

		$tags['br'] = array();
		$tags['hr'] = $common_attrs;

		$tags['details']['open'] = true;

		$tags['a'] = array_merge(
			$common_attrs,
			array(
				'href'   => true,
				'rel'    => true,
				'target' => true,
			)
		);

		$tags['img'] = array_merge(
			$common_attrs,
			array(
				'src'      => true,
				'srcset'   => true,
				'sizes'    => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'loading'  => true,
			)
		);

		$tags['video'] = array_merge(
			$common_attrs,
			array(
				'src'      => true,
				'controls' => true,
				'width'    => true,
				'height'   => true,
			)
		);

		$tags['button'] = array_merge( $common_attrs, array( 'type' => true, 'aria-label' => true ) );

		// Элементы форм ("разные инпуты" — см. elements.js): без сервера,
		// принимающего эти данные, — сами теги/атрибуты нужны только чтобы
		// готовый HTML-виджет вообще дожил до фронтенда в исходном виде.
		$tags['input'] = array_merge(
			$common_attrs,
			array(
				'type'        => true,
				'name'        => true,
				'value'       => true,
				'placeholder' => true,
				'checked'     => true,
			)
		);
		$tags['textarea'] = array_merge(
			$common_attrs,
			array(
				'name'        => true,
				'placeholder' => true,
				'rows'        => true,
			)
		);
		$tags['select'] = array_merge( $common_attrs, array( 'name' => true ) );
		$tags['option'] = array_merge( $common_attrs, array( 'value' => true, 'selected' => true ) );

		$tags['th'] = array_merge( $common_attrs, array( 'scope' => true, 'aria-sort' => true ) );
		$tags['td'] = $common_attrs;

		// Элемент "Иконка" (elements.js: wpgjb-icon) — инлайн SVG, единственный
		// способ доставить произвольную форму без растрового файла/иконочного
		// шрифта. `path/d` — только геометрия контура, исполняемого содержимого
		// (script/on*) в SVG-разметке, которую мы сами генерируем, нет.
		$tags['svg'] = array(
			'class'    => true,
			'style'    => true,
			'viewbox'  => true,
			'width'    => true,
			'height'   => true,
			'fill'     => true,
			'aria-hidden' => true,
		);
		$tags['path'] = array(
			'class' => true,
			'd'     => true,
			'fill'  => true,
		);
		$tags['polyline'] = array(
			'class'            => true,
			'points'           => true,
			'fill'             => true,
			'stroke'           => true,
			'stroke-width'     => true,
			'stroke-linecap'   => true,
			'stroke-linejoin'  => true,
		);
		$tags['circle'] = array(
			'class' => true,
			'cx'    => true,
			'cy'    => true,
			'r'     => true,
			'fill'  => true,
		);

		return $tags;
	}

	/**
	 * HTML-санитизация по whitelist тегов/атрибутов. Тонкая обёртка над
	 * wp_kses() ядра WP — вся логика отклонения <script>/on*-атрибутов
	 * принадлежит wp_kses, здесь она не дублируется и не переопределяется.
	 *
	 * @param string $html         Сырой HTML для конкретного слота/значения.
	 * @param array  $allowed_tags Формат wp_kses(): tag => array(attr => true|array(...)).
	 */
	public static function sanitize_html( string $html, array $allowed_tags ): string {
		return wp_kses( $html, $allowed_tags );
	}

	/**
	 * CSS-санитизация по whitelist свойств. См. предупреждение о
	 * спайк-качестве этой реализации в шапке файла.
	 *
	 * @param string $css                 Сырой CSS: либо набор правил
	 *                                     `selector { prop: value; }`,
	 *                                     либо плоский список деклараций
	 *                                     `prop: value; prop: value;`
	 *                                     (как содержимое style="").
	 * @param array  $allowed_properties  Whitelist имён CSS-свойств (без учёта регистра).
	 */
	public static function sanitize_css( string $css, array $allowed_properties ): string {
		// 1. Снять комментарии ДО любых проверок по чёрному списку — иначе
		//    комментарий может разбить запрещённое слово и обойти regex
		//    (классический байпас вида "exp/**/ression(").
		$css = (string) preg_replace( '!/\*.*?\*/!s', '', $css );

		// 2. Убрать любые @-правила целиком (@import, @charset, @font-face,
		//    @media...) — whitelist этого спайка не моделирует @-правила;
		//    это же напрямую убирает @import как поверхность инъекции.
		//    Полноценная обработка вложенных @media — задача Phase 6
		//    парсер-based реализации.
		$css = (string) preg_replace( '/@[a-zA-Z-]+[^;{}]*(;|\{[^{}]*\})/', '', $css );

		if ( false !== strpos( $css, '{' ) ) {
			return self::sanitize_css_rules( $css, $allowed_properties );
		}

		return self::sanitize_css_declarations( $css, $allowed_properties );
	}

	private static function sanitize_css_rules( string $css, array $allowed_properties ): string {
		$out = '';

		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$selector = self::sanitize_selector( $match[1] );
				if ( '' === $selector ) {
					continue;
				}

				$decls = self::sanitize_css_declarations( $match[2], $allowed_properties );
				if ( '' === trim( $decls ) ) {
					continue;
				}

				$out .= $selector . ' { ' . $decls . ' }' . "\n";
			}
		}

		return $out;
	}

	/** Консервативный whitelist символов селектора для этого спайка. */
	private static function sanitize_selector( string $selector ): string {
		$selector = trim( $selector );

		if ( '' === $selector || ! preg_match( '/^[a-zA-Z0-9\.\#_\-\s,>~+:()\[\]="\'\x20]+$/', $selector ) ) {
			return '';
		}

		return $selector;
	}

	private static function sanitize_css_declarations( string $declarations, array $allowed_properties ): string {
		$allowed_lc = array_map( 'strtolower', $allowed_properties );
		$out        = array();

		foreach ( explode( ';', $declarations ) as $decl ) {
			$decl = trim( $decl );
			if ( '' === $decl ) {
				continue;
			}

			// Проверка чёрного списка на ВСЮ декларацию (свойство+значение) —
			// ловит и случай, когда запрещённое имя свойства (-moz-binding)
			// само по себе не в whitelist (уже было бы отброшено ниже), и
			// случай обфускации внутри значения (expression(), javascript:).
			if ( self::declaration_has_forbidden_pattern( $decl ) ) {
				continue;
			}

			$pos = strpos( $decl, ':' );
			if ( false === $pos ) {
				continue;
			}

			$prop  = strtolower( trim( substr( $decl, 0, $pos ) ) );
			$value = trim( substr( $decl, $pos + 1 ) );

			if ( '' === $prop || '' === $value ) {
				continue;
			}

			if ( ! in_array( $prop, $allowed_lc, true ) ) {
				continue;
			}

			if ( false !== stripos( $value, 'url(' ) && ! self::url_value_safe( $value ) ) {
				continue;
			}

			if ( ! self::value_shape_ok( $prop, $value ) ) {
				continue;
			}

			$out[] = $prop . ': ' . $value . ';';
		}

		return implode( ' ', $out );
	}

	private static function declaration_has_forbidden_pattern( string $decl ): bool {
		foreach ( self::CSS_FORBIDDEN_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $decl ) ) {
				return true;
			}
		}
		return false;
	}

	private static function url_value_safe( string $value ): bool {
		if ( preg_match_all( '/url\(\s*[\'"]?(.*?)[\'"]?\s*\)/i', $value, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				// Убрать пробельные/управляющие символы — байпас вида "java\tscript:".
				$normalized = (string) preg_replace( '/[\s\x00-\x1F]+/', '', $url );
				if ( preg_match( '#^(javascript|vbscript|data):#i', $normalized ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** Базовая проверка "формы" значения по типу свойства. */
	private static function value_shape_ok( string $prop, string $value ): bool {
		// Символы, запрещённые в значении для любого свойства этого спайка:
		// обратный слэш, фигурные/угловые скобки — не встречаются в
		// легитимных CSS-значениях из нашего whitelist, зато типичны для
		// попыток вырваться из контекста значения.
		if ( preg_match( '/[\\\\{}<>]/', $value ) ) {
			return false;
		}

		$color_pattern = '/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9,.\s%]+\)|hsla?\([0-9,.\s%]+\)|[a-zA-Z]+)$/';

		$exact_shapes = array(
			'color'            => $color_pattern,
			'background-color' => $color_pattern,
			'font-weight'      => '/^(normal|bold|bolder|lighter|[1-9]00)$/',
			'text-align'       => '/^(left|right|center|justify)$/',
		);

		if ( isset( $exact_shapes[ $prop ] ) ) {
			return 1 === preg_match( $exact_shapes[ $prop ], $value );
		}

		$length_props = array(
			'font-size',
			'margin',
			'margin-top',
			'margin-right',
			'margin-bottom',
			'margin-left',
			'padding',
			'padding-top',
			'padding-right',
			'padding-bottom',
			'padding-left',
			'border-radius',
			'line-height',
		);

		if ( in_array( $prop, $length_props, true ) ) {
			$len = '-?[0-9]*\.?[0-9]+(px|em|rem|%|vh|vw|pt)?';
			return 1 === preg_match( '/^' . $len . '(\s+' . $len . '){0,3}$/', $value );
		}

		// Консервативный дефолт для остальных whitelisted свойств: буквы,
		// цифры, # % . , - пробел, кавычки, двоеточие/скобки/слэш (нужны
		// для url(...), уже проверенного выше на javascript:/data:).
		return 1 === preg_match( '/^[a-zA-Z0-9#%.,\-\s()\/\'":_]+$/', $value );
	}
}
