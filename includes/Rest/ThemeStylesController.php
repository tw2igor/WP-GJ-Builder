<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\Blocks\BlockLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Наследование стиля темы в canvas (раздел 5.2/6 спеки, вердикт спайка 1,
 * docs/adr/spike-1-theme-canvas-verdict.md). Три маршрута: (1) реальный
 * сгенерированный global-styles stylesheet как CSS-текст — для
 * `canvas.styles` (byte-в-byte совпадение с фронтендом,
 * `wp_get_global_stylesheet()` не публикуется как статический файл); (2)
 * CSS платформенных блоков (стили из blocks-library/*\/style.css) — тоже
 * для `canvas.styles`, иначе только что вставленный блок в canvas visually
 * не совпадает с тем, что увидит посетитель; (3) токены темы как JSON —
 * для `grapesjs-css-variables` presets.
 *
 * ВАЖНО (обнаружено реальным прогоном в браузере, не curl): (1) и (2)
 * подключаются в canvas через `<link rel="stylesheet">` — такой запрос
 * физически не может нести заголовок `X-WP-Nonce`, а cookie-аутентификация
 * REST API требует его для ЛЮБОГО запроса (`rest_cookie_check_errors()`),
 * не только для небезопасных методов. Гейтить эти два маршрута
 * капабилити поэтому нельзя технически — но и не нужно содержательно:
 * это те же самые CSS-переменные/классы, что тема и так публично отдаёт
 * каждому посетителю сайта, не приватные данные. Токены (3) читаются
 * через fetch() с nonce из JS — остаются под капабилити.
 */
class ThemeStylesController {

	const ROUTE_GLOBAL_STYLES      = 'wpgjb/v1/theme/global-styles.css';
	const ROUTE_BLOCKS_STYLE       = 'wpgjb/v1/blocks/style.css';
	const ROUTE_CUSTOM_BACKGROUND  = 'wpgjb/v1/theme/custom-background.css';

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/theme/global-styles.css',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_global_styles_css' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpgjb/v1',
			'/blocks/style.css',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_blocks_style_css' ),
				'permission_callback' => '__return_true',
			)
		);

		/**
		 * Раздел задачи: "просто наследуй все стили темы, включая фон" —
		 * `theme.json`/global-styles (уже подключены) покрывают только
		 * блочные темы; классические темы с `add_theme_support(
		 * 'custom-background')` задают фон через ОТДЕЛЬНЫЙ WP-механизм
		 * (Customizer -> `wp_head`-callback), совсем не через свой
		 * style.css — без этого маршрута фон Customizer'а никогда не
		 * попадёт в canvas ни для одной темы, которая так его настроила.
		 */
		register_rest_route(
			'wpgjb/v1',
			'/theme/custom-background.css',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_custom_background_css' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'wpgjb/v1',
			'/theme/tokens',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_tokens' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			'wpgjb/v1',
			'/theme/chrome',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_chrome' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		// WP_REST_Server всегда JSON-кодирует тело ответа и форсирует
		// Content-Type: application/json при обычной сериализации —
		// браузер отказывается применять такой <link rel="stylesheet">.
		// rest_pre_serve_request — штатный способ отдать НЕ-JSON тело
		// через REST API в обход этой сериализации.
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_raw_css' ), 10, 4 );
	}

	public static function serve_raw_css( $served, $result, $request, $server ) {
		$route = ltrim( $request->get_route(), '/' );
		if ( self::ROUTE_GLOBAL_STYLES !== $route && self::ROUTE_BLOCKS_STYLE !== $route && self::ROUTE_CUSTOM_BACKGROUND !== $route ) {
			return $served;
		}

		header( 'Content-Type: text/css; charset=utf-8' );
		if ( self::ROUTE_BLOCKS_STYLE === $route ) {
			// Раздел 11: "общий, долгокэшируемый файл платформенных блоков" —
			// теперь также подключается на фронтенде (FrontendRenderer), не
			// только в canvas редактора. Публичный + агрессивный кэш безопасны,
			// потому что URL версионируется хэшем содержимого (см.
			// blocks_style_version()) — при изменении блоков меняется версия
			// в query-параметре, старый закэшированный файл больше не запросится.
			header( 'Cache-Control: public, max-age=31536000, immutable' );
		} else {
			header( 'Cache-Control: private, max-age=60' );
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- сгенерировано WP core / собственные файлы плагина, не пользовательский ввод.

		return true;
	}

	public static function check_permission() {
		return current_user_can( 'wpgjb_edit_pages' );
	}

	public static function get_global_styles_css() {
		$css = function_exists( 'wp_get_global_stylesheet' ) ? wp_get_global_stylesheet() : '';

		return new \WP_REST_Response( $css );
	}

	public static function get_blocks_style_css() {
		return new \WP_REST_Response( self::concatenated_blocks_css() );
	}

	/**
	 * Классические темы часто задают фон не в своём style.css, а через
	 * `add_theme_support('custom-background')` — цвет/картинка приходят из
	 * Настройщика (theme mods), печатаются штатным `wp_head`-callback'ом
	 * темы (обычно `_custom_background_cb()`, но берём РЕАЛЬНО
	 * зарегистрированный колбэк, а не хардкодим имя — тема могла указать
	 * свой). Если тема не поддерживает фичу или фон не задан — колбэк
	 * либо не существует, либо ничего не печатает; в обоих случаях просто
	 * возвращаем пустую строку, ничего страшного.
	 */
	public static function get_custom_background_css() {
		$css = '';

		if ( function_exists( 'current_theme_supports' ) && current_theme_supports( 'custom-background' ) ) {
			$callback = get_theme_support( 'custom-background', 'wp-head-callback' );
			if ( $callback && is_callable( $callback ) ) {
				ob_start();
				call_user_func( $callback );
				$raw = ob_get_clean();
				$css = trim( (string) preg_replace( '#</?style[^>]*>#i', '', $raw ) );
			}
		}

		return new \WP_REST_Response( $css );
	}

	private static function concatenated_blocks_css(): string {
		$css = '';
		foreach ( BlockLibrary::all() as $block_id => $block ) {
			if ( ! empty( $block['style'] ) ) {
				$css .= "/* {$block_id} */\n" . $block['style'] . "\n";
			}
		}
		return $css;
	}

	/**
	 * Хэш содержимого всех style.css библиотеки — версия для кэш-бастинга
	 * общего файла платформенных блоков (раздел 11). Меняется САМА, когда
	 * меняется набор/содержимое блоков — не нужно вручную поднимать версию.
	 */
	public static function blocks_style_version(): string {
		return substr( md5( self::concatenated_blocks_css() ), 0, 12 );
	}

	public static function get_chrome() {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return new \WP_REST_Response(
				array(
					'header'       => '',
					'footer'       => '',
					'isBlockTheme' => false,
				)
			);
		}

		$theme_slug   = get_stylesheet();
		$header_attrs = wp_json_encode( array( 'slug' => 'header', 'theme' => $theme_slug, 'tagName' => 'header' ) );
		$footer_attrs = wp_json_encode( array( 'slug' => 'footer', 'theme' => $theme_slug, 'tagName' => 'footer' ) );

		return new \WP_REST_Response(
			array(
				'header'       => do_blocks( '<!-- wp:template-part ' . $header_attrs . ' /-->' ),
				'footer'       => do_blocks( '<!-- wp:template-part ' . $footer_attrs . ' /-->' ),
				'isBlockTheme' => true,
			)
		);
	}

	public static function get_tokens() {
		$tokens = array();

		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			if ( is_array( $palette ) ) {
				foreach ( $palette as $color ) {
					if ( empty( $color['slug'] ) || ! isset( $color['color'] ) ) {
						continue;
					}
					$tokens[] = array(
						'name'  => 'wp--preset--color--' . $color['slug'],
						'value' => $color['color'],
						'type'  => 'color',
						'label' => $color['name'] ?? $color['slug'],
					);
				}
			}

			$font_sizes = wp_get_global_settings( array( 'typography', 'fontSizes' ) );
			if ( is_array( $font_sizes ) ) {
				foreach ( $font_sizes as $size ) {
					if ( empty( $size['slug'] ) || ! isset( $size['size'] ) ) {
						continue;
					}
					$tokens[] = array(
						'name'  => 'wp--preset--font-size--' . $size['slug'],
						'value' => $size['size'],
						'type'  => 'size',
						'label' => $size['name'] ?? $size['slug'],
					);
				}
			}

			$font_families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
			if ( is_array( $font_families ) ) {
				foreach ( $font_families as $family ) {
					if ( empty( $family['slug'] ) || ! isset( $family['fontFamily'] ) ) {
						continue;
					}
					$tokens[] = array(
						'name'  => 'wp--preset--font-family--' . $family['slug'],
						'value' => $family['fontFamily'],
						'type'  => 'font-family',
						'label' => $family['name'] ?? $family['slug'],
					);
				}
			}
		}

		$source = ! empty( $tokens ) ? 'theme.json' : 'none';

		if ( empty( $tokens ) ) {
			// Классическая тема без theme.json: тот же, более скромный
			// набор, что предлагает ядру сама тема через add_theme_support().
			$color_palette = get_theme_support( 'editor-color-palette' );
			if ( ! empty( $color_palette[0] ) ) {
				foreach ( $color_palette[0] as $color ) {
					if ( empty( $color['slug'] ) || ! isset( $color['color'] ) ) {
						continue;
					}
					$tokens[] = array(
						'name'  => $color['slug'],
						'value' => $color['color'],
						'type'  => 'color',
						'label' => $color['name'] ?? $color['slug'],
					);
				}
				$source = 'classic-palette';
			}

			$font_sizes_support = get_theme_support( 'editor-font-sizes' );
			if ( ! empty( $font_sizes_support[0] ) ) {
				foreach ( $font_sizes_support[0] as $size ) {
					if ( empty( $size['slug'] ) || ! isset( $size['size'] ) ) {
						continue;
					}
					$tokens[] = array(
						'name'  => $size['slug'],
						'value' => $size['size'] . 'px',
						'type'  => 'size',
						'label' => $size['name'] ?? $size['slug'],
					);
				}
				$source = 'classic-palette';
			}
		}

		return new \WP_REST_Response(
			array(
				'tokens' => $tokens,
				'source' => $source,
			)
		);
	}
}
