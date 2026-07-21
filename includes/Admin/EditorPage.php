<?php

namespace WPGJBuilder\Admin;

use WPGJBuilder\Blocks\BlockLibrary;
use WPGJBuilder\Blocks\TemplateLibrary;
use WPGJBuilder\Render\PageTemplates;
use WPGJBuilder\Rest\ThemeStylesController;
use WPGJBuilder\Storage\DocumentRepository;
use WPGJBuilder\Storage\MigrationRunner;

defined( 'ABSPATH' ) || exit;

/**
 * Полноэкранный редактор (раздел 4/5 спеки): НЕ пункт меню — скрытая
 * страница (`add_submenu_page` с `null`-родителем), открываемая со
 * страницы «Страницы» (действие «Редактировать в конструкторе» / кнопка
 * «Добавить в конструкторе»). Редакторский бандл подключается ТОЛЬКО на
 * этом экране (`get_current_screen()->id` гейт в `maybe_enqueue()`) —
 * раздел 11: "ни один ассет конструктора не подключается ни в остальной
 * админке, ни на фронтенде".
 */
class EditorPage {

	const SLUG = 'wpgjb-editor';

	/**
	 * Раздел задачи: "чтобы можно было язык самому переключать при
	 * необходимости" — короткий код в query-параметре `wpgjb_lang`
	 * (`?wpgjb_lang=en`, кнопка-переключатель в status-strip добавляет его
	 * сама, см. index.js) сопоставляется с полной WP-локалью для
	 * `switch_to_locale()`. Только эти два языка — не общий i18n-фреймворк
	 * для произвольных локалей.
	 */
	const UI_LOCALES = array(
		'ru' => 'ru_RU',
		'en' => 'en_US',
	);

	private static $hook_suffix = null;

	public static function register_hooks() {
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
		add_filter( 'page_row_actions', array( self::class, 'add_row_action' ), 10, 2 );
		add_action( 'admin_action_wpgjb_new_page', array( self::class, 'handle_new_page' ) );
		add_action( 'manage_posts_extra_tablenav', array( self::class, 'render_new_page_button' ) );
		add_action( 'manage_posts_extra_tablenav', array( self::class, 'render_ai_generate_button' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue_ai_admin_assets' ) );
	}

	public static function register_page() {
		self::$hook_suffix = add_submenu_page(
			null, // Скрыт из любого меню — доступен только по прямой ссылке.
			__( 'Конструктор', 'wp-gj-builder' ),
			__( 'Конструктор', 'wp-gj-builder' ),
			'wpgjb_edit_pages',
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/** `?wpgjb_lang=en|ru` query-параметр — иначе локаль сайта/пользователя как обычно (WP решает через determine_locale()). */
	private static function resolve_ui_lang() {
		$requested = isset( $_GET['wpgjb_lang'] ) ? sanitize_key( $_GET['wpgjb_lang'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( self::UI_LOCALES[ $requested ] ) ) {
			return $requested;
		}
		return 0 === strpos( determine_locale(), 'ru' ) ? 'ru' : 'en';
	}

	/**
	 * Приближённый набор `body_class()` — намеренно НЕ через реальный
	 * `WP_Query`/`get_body_class()` (та связка требует настройки
	 * глобального запроса под конкретную запись, что усложняет и без
	 * видимой пользы здесь — нужны только классы, за которые классические
	 * темы обычно зацепляют фон/типографику).
	 */
	private static function compute_body_class( $post, $type ): string {
		if ( ! $post || 'page' !== $type ) {
			return '';
		}

		$classes = array( 'page', 'page-id-' . $post->ID );

		if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
			$classes[] = 'home';
		}

		$template = get_page_template_slug( $post->ID );
		if ( $template ) {
			$classes[] = 'page-template-' . sanitize_html_class( str_replace( array( '.', '/' ), '-', $template ) );
		}

		// Зеркалит условие core get_body_class() (wp-includes/post-template.php)
		// — без класса `custom-background` правило `_custom_background_cb()`
		// (селектор всегда `body.custom-background`, см. wp-includes/theme.php)
		// не совпадёт вообще ни с чем, даже если сам CSS уже подключён.
		if (
			current_theme_supports( 'custom-background' )
			&& ( get_background_color() !== get_theme_support( 'custom-background', 'default-color' ) || get_background_image() )
		) {
			$classes[] = 'custom-background';
		}

		return implode( ' ', $classes );
	}

	public static function editor_url( $post_id, $type = 'page' ) {
		return add_query_arg(
			array(
				'page'    => self::SLUG,
				'post_id' => (int) $post_id,
				'type'    => $type,
			),
			admin_url( 'admin.php' )
		);
	}

	public static function add_row_action( $actions, $post ) {
		if ( 'page' !== $post->post_type || ! current_user_can( 'wpgjb_edit_pages' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['wpgjb_edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::editor_url( $post->ID ) ),
			esc_html__( 'Редактировать в конструкторе', 'wp-gj-builder' )
		);

		return $actions;
	}

	public static function render_new_page_button( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		global $typenow;
		if ( 'page' !== $typenow || ! current_user_can( 'wpgjb_edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}

		printf(
			'<a href="%s" class="button button-primary" style="margin-left:8px;">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin.php?action=wpgjb_new_page' ), 'wpgjb_new_page' ) ),
			esc_html__( 'Добавить в конструкторе', 'wp-gj-builder' )
		);
	}

	public static function handle_new_page() {
		if ( ! current_user_can( 'wpgjb_edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}
		check_admin_referer( 'wpgjb_new_page' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => __( 'Новая страница', 'wp-gj-builder' ),
			)
		);

		update_post_meta( $post_id, '_wp_page_template', PageTemplates::DEFAULT_TEMPLATE );

		wp_safe_redirect( self::editor_url( $post_id ) );
		exit;
	}

	/**
	 * Раздел 9 спеки, AI-фаза: точка входа рядом с "Добавить в
	 * конструкторе" — сама кнопка ничего не отправляет (никакой
	 * admin-action-ссылки, в отличие от `render_new_page_button()`), это
	 * просто триггер модалки из `assets/admin/index.js`
	 * (`#wpgjb-ai-generate-btn`), которая сама зовёт REST `/ai/generate-page`.
	 */
	public static function render_ai_generate_button( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		global $typenow;
		if ( 'page' !== $typenow || ! current_user_can( 'wpgjb_edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}

		printf(
			'<button type="button" id="wpgjb-ai-generate-btn" class="button" style="margin-left:8px;">%s</button>',
			esc_html__( 'Создать с помощью AI', 'wp-gj-builder' )
		);
	}

	/** Подключает `assets/admin/index.js` ТОЛЬКО на списке страниц (`edit.php?post_type=page`) — не на всей остальной админке, по тому же принципу, что и редакторский бандл. */
	public static function maybe_enqueue_ai_admin_assets( $hook_suffix ) {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'page' !== $screen->post_type ) {
			return;
		}
		if ( ! current_user_can( 'wpgjb_edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}

		$asset_file = WPGJB_PLUGIN_DIR . 'assets/build/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => WPGJB_VERSION,
			);

		wp_enqueue_script( 'wpgjb-admin', WPGJB_PLUGIN_URL . 'assets/build/admin.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'wpgjb-admin', 'wp-gj-builder', WPGJB_PLUGIN_DIR . 'languages' );

		wp_localize_script(
			'wpgjb-admin',
			'wpgjbAiGenerator',
			array(
				'restRoot'  => esc_url_raw( rest_url() ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'namespace' => 'wpgjb/v1',
			)
		);
	}

	public static function render_page() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post    = get_post( $post_id );

		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Страница не найдена или недостаточно прав.', 'wp-gj-builder' ) );
		}

		echo '<div id="wpgjb-editor-root" class="wpgjb-editor-root"></div>';
	}

	public static function maybe_enqueue( $hook_suffix ) {
		if ( $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type    = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'page'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post    = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$asset_file = WPGJB_PLUGIN_DIR . 'assets/build/editor.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => WPGJB_VERSION,
			);

		$ui_lang = self::resolve_ui_lang();
		if ( self::UI_LOCALES[ $ui_lang ] !== determine_locale() ) {
			// НЕ switch_to_locale(): она молча отказывается переключать на
			// локаль, для которой на сайте не установлен полноценный
			// языковой пакет ядра (WP_Locale_Switcher::switch_to_locale()
			// проверяет available_languages() — обнаружено эмпирическим
			// прогоном, не по документации: на тестовом стенде без
			// установленного ru_RU-пакета переключение молча ничего не
			// делало). 'pre_determine_locale' и 'locale' — те же фильтры,
			// которыми пользуются determine_locale()/get_locale() — не
			// имеют такого гейта, работают независимо от того, установлен
			// ли языковой пакет ядра целиком (нам нужен перевод ТОЛЬКО
			// строк этого плагина, не всего ядра WP).
			$target_locale = self::UI_LOCALES[ $ui_lang ];
			add_filter( 'pre_determine_locale', fn() => $target_locale );
			add_filter( 'locale', fn() => $target_locale );
		}

		// Раздел 11 спеки (изображения): реальный медиа-пикер вместо ручного
		// ввода ID вложения — стандартный способ подключить wp.media на
		// произвольном экране админки, сам по себе ничего не рендерит.
		wp_enqueue_media();

		wp_enqueue_script( 'wpgjb-editor', WPGJB_PLUGIN_URL . 'assets/build/editor.js', $asset['dependencies'], $asset['version'], true );

		// Раздел 13: JS-строки редактора теперь реально переводимы (были
		// заглушкой-идентити до аудита Phase 7, см. assets/editor/index.js).
		// wp_set_script_translations()/load_script_textdomain() сами берут
		// текущую локаль через determine_locale() — переключение выше уже
		// достаточно, здесь ничего дополнительно передавать не нужно.
		wp_set_script_translations( 'wpgjb-editor', 'wp-gj-builder', WPGJB_PLUGIN_DIR . 'languages' );

		if ( file_exists( WPGJB_PLUGIN_DIR . 'assets/build/editor.css' ) ) {
			wp_enqueue_style( 'wpgjb-editor', WPGJB_PLUGIN_URL . 'assets/build/editor.css', array(), $asset['version'] );
		}

		// Документ для редактирования — напрямую через тот же слой, что и
		// DocumentsController::get_document(), без лишнего REST round-trip
		// на старте экрана.
		$repository = new DocumentRepository( new MigrationRunner() );
		$doc        = $repository->get_for_edit( $post_id, $type );

		// Штатный WP-механизм блокировки редактирования (раздел 5.5 спеки:
		// "штатный механизм WordPress, Heartbeat API"), тот же
		// _edit_lock/_edit_last, что использует post.php. Проверяем ДО
		// того как захватить лок сами — если уже держит другой
		// пользователь, не перезаписываем его лок молча.
		$locked_by  = null;
		$lock_owner = wp_check_post_lock( $post_id );
		if ( $lock_owner ) {
			$user = get_userdata( $lock_owner );
			if ( $user ) {
				$locked_by = array(
					'id'   => $user->ID,
					'name' => $user->display_name,
				);
			}
		} else {
			wp_set_post_lock( $post_id );
		}

		wp_enqueue_script( 'heartbeat' );

		wp_localize_script(
			'wpgjb-editor',
			'wpgjbEditorData',
			array(
				'postId'     => $post_id,
				'type'       => $type,
				'postTitle'  => get_the_title( $post ),
				'postSlug'   => $post->post_name,
				'postStatus' => $post->post_status,
				'featuredMedia'    => (int) get_post_thumbnail_id( $post_id ),
				'featuredMediaUrl' => get_the_post_thumbnail_url( $post_id, 'thumbnail' ) ?: '',
				'uiLang'     => $ui_lang,
				// Части сайта (header/footer) не самостоятельная страница —
				// осмысленного предпросмотра по прямой ссылке нет.
				'previewUrl' => 'page' === $type ? get_permalink( $post_id ) : null,
				'adminUrl'   => 'page' === $type
					? admin_url( 'edit.php?post_type=page' )
					: admin_url( 'admin.php?page=wpgjb-site-parts&tab=' . $type ),
				'restRoot'   => esc_url_raw( rest_url() ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'namespace'  => 'wpgjb/v1',
				// canvas.styles (вердикт спайка 1): порядок важен — core, затем
				// тема, затем global-styles (переменные должны быть объявлены
				// раньше, чем используются через var()), затем CSS платформенных
				// блоков (сами блоки используют var(--wp--preset--*, ...)).
				'canvasStyles' => array(
					includes_url( 'css/dist/block-library/style.min.css' ),
					includes_url( 'css/dist/block-library/theme.min.css' ),
					get_stylesheet_uri(),
					esc_url_raw( rest_url( 'wpgjb/v1/theme/global-styles.css' ) ),
					// Версия в query — теперь агрессивно (год, immutable)
					// кэшируется браузером на фронтенде (раздел 11), поэтому
					// URL обязан меняться при изменении содержимого блоков,
					// иначе canvas редактора показывал бы устаревший стиль
					// до года после правки style.css блока.
					esc_url_raw( add_query_arg( 'v', ThemeStylesController::blocks_style_version(), rest_url( 'wpgjb/v1/blocks/style.css' ) ) ),
					// Последним: фон из Настройщика (add_theme_support('custom-
					// background')) — отдельный от theme.json/style.css механизм,
					// без него классические темы с настроенным через Настройщик
					// фоном показывали бы белый canvas вместо реального цвета/картинки.
					esc_url_raw( rest_url( 'wpgjb/v1/theme/custom-background.css' ) ),
				),
				// Раздел задачи: "сразу видно, как будут выглядеть элементы со
				// стилями темы" — многие классические темы задают фон/цвет НЕ
				// голым `body{...}`, а через классы вида `.home`/`.page`/
				// `.page-template-X` (стандартный `body_class()` набор) —
				// без них часть правил темы просто не совпадает ни с чем в
				// пустом canvas-документе. Не вызывает WP_Query — минимальный,
				// безопасный набор, вычисленный вручную из уже известных данных
				// о посте (см. index.js: применяется прямым назначением
				// className, без какой-либо перестройки DOM).
				'bodyClass'  => self::compute_body_class( $post, $type ),
				'document'   => array(
					'status'      => $doc['status'],
					'projectData' => $doc['project_data'],
					'docVersion'  => $doc['doc_version'],
					'error'       => $doc['error'],
				),
				'blocks'     => self::export_blocks_for_js(),
				'templates'  => self::export_templates_for_js(),
				'pageTemplates' => 'page' === $type ? self::export_page_templates_for_js( $post_id ) : null,
				'lock'       => array(
					'lockedBy' => $locked_by,
				),
			)
		);
	}

	private static function export_blocks_for_js() {
		$blocks = array();
		foreach ( BlockLibrary::all_visible_to_current_user() as $id => $block ) {
			$blocks[ $id ] = array(
				'manifest' => $block['manifest'],
				'markup'   => $block['markup'],
				'style'    => $block['style'],
			);
		}
		return $blocks;
	}

	/** "Страницы" (третий уровень вложенности) — зеркало export_blocks_for_js(). */
	private static function export_templates_for_js() {
		return TemplateLibrary::all_visible_to_current_user();
	}

	private static function export_page_templates_for_js( $post_id ) {
		$templates = get_page_templates( get_post( $post_id ) );
		$options   = array( '' => __( 'По умолчанию', 'wp-gj-builder' ) );
		foreach ( $templates as $label => $file ) {
			$options[ $file ] = $label;
		}

		return array(
			'options' => $options,
			'current' => (string) get_post_meta( $post_id, '_wp_page_template', true ),
		);
	}
}
