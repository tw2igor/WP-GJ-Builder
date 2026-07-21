<?php

namespace WPGJBuilder\Render;

use WPGJBuilder\Blocks\BlockFaultTolerance;
use WPGJBuilder\Sanitize\Contour2PrePublish;
use WPGJBuilder\Sanitize\Sanitizer;
use WPGJBuilder\Storage\DocumentRepository;
use WPGJBuilder\Storage\MigrationRunner;

defined( 'ABSPATH' ) || exit;

/**
 * Publish-пайплайн (раздел 3 спеки, план Phase 2): клиент уже сгенерировал
 * html/css на своей стороне (`editor.getHtml()`/`getCss()` — GrapesJS не
 * рендерится на сервере, см. builder-analysis.md §8.3/§7.2), сервер:
 * контур-2 санитизация НЕЗАВИСИМО от контура-1 → новая ревизия
 * project_json (revision_type=publish) → запись HTML в post_content через
 * wp_update_post() (не сырой SQL — чтобы штатные хуки save_post/
 * clean_post_cache сработали для кэш-плагинов хостинга автоматически).
 *
 * ВАЖНО (найдено реальным кросс-тематическим прогоном Phase 4, не
 * заметно в code review): `editor.getCss()` отдаёт ТОЛЬКО правила,
 * которые GrapesJS сам создал через свой CssComposer (переопределения из
 * Style Manager) — базовый `style.css` каждого блока подключается в
 * canvas отдельно, как обычный `<link>` (см. canvas.styles, вердикт
 * спайка 1), и GrapesJS о нём вообще не знает, поэтому в getCss() его
 * нет.
 *
 * Раздел 11 (Phase 6): базовый CSS блоков публикуется НЕ инлайном в каждую
 * страницу (как было в Phase 4 — целиком дублировался на каждой странице
 * сайта), а одним общим, долгокэшируемым файлом
 * (`GET /wpgjb/v1/blocks/style.css`, тот же, что уже используется в canvas
 * редактора — см. ThemeStylesController), который подключает
 * FrontendRenderer через wp_enqueue_style(). Здесь, в `_wpb_page_css`,
 * хранится и на фронтенде инлайнится ТОЛЬКО маленький per-page файл —
 * пользовательские переопределения из Style Manager (`$css` — то, что
 * реально возвращает `editor.getCss()`), не базовые стили блоков.
 */
class Publisher {

	const META_BUILT = '_wpb_built';
	const META_CSS    = '_wpb_page_css';

	/**
	 * @return array{html: string, css: string}
	 */
	public static function publish( int $post_id, string $type, array $project_data, string $html, string $css, int $user_id ): array {
		// Раздел 12: один повреждённый/неизвестный блок (данные ссылаются на
		// удалённый из библиотеки block_id, или узел структурно испорчен) не
		// должен ронять публикацию всей страницы — карантин заменяет только
		// проблемный узел невидимой заглушкой и логирует инцидент, остальное
		// дерево публикуется как есть.
		list( $project_data, $quarantine_report ) = BlockFaultTolerance::quarantine( $project_data );

		// Контур 2 — независимо от того, прошёл ли документ через контур 1
		// (раздел 10: обязателен даже при прямом forged REST-вызове на этот
		// же эндпоинт). Санитизируется ТОЛЬКО пользовательский override CSS —
		// базовые стили блоков не пользовательский ввод, живут в общем файле.
		$sanitized = Contour2PrePublish::sanitize_document(
			$html,
			$css,
			Sanitizer::richness_page(),
			Sanitizer::CSS_PROPERTIES_PAGE
		);

		$repository = new DocumentRepository( new MigrationRunner() );
		$repository->publish( $post_id, $type, $project_data, $user_id );

		// Раздел 4/7: и страницы, и части сайта создаются вне обычного экрана
		// post.php (скрытый редактор конструктора) — эта кнопка "Опубликовать"
		// единственное место, где пользователь переводит документ в видимое
		// посетителям состояние. Без явного перевода статуса черновик так и
		// останется draft навсегда, и ни одна страница/часть сайта никогда не
		// станет видна фронтенду (найдено чтением реального пути создания
		// части сайта, до этого маскировалось тестами, вручную звавшими
		// wp_publish_post()). Не трогаем посты в других статусах (private,
		// pending и т.п.) — только неопубликованный черновик.
		$html = $sanitized['html'];
		if ( PageTemplates::is_full_width_on_block_theme( $post_id ) ) {
			$html = '<div class="alignfull">' . $html . '</div>';
		}

		$post = get_post( $post_id );
		$args = array(
			'ID'           => $post_id,
			'post_content' => $html,
		);
		if ( $post && in_array( $post->post_status, array( 'draft', 'auto-draft', 'pending' ), true ) ) {
			$args['post_status'] = 'publish';
		}

		wp_update_post( $args );

		update_post_meta( $post_id, self::META_BUILT, '1' );
		update_post_meta( $post_id, self::META_CSS, $sanitized['css'] );

		// Раздел 11: JS конкретных блоков резолвится ОДИН РАЗ здесь (не на
		// каждый запрос) — wp_enqueue_scripts на фронтенде просто читает
		// готовый список из postmeta.
		update_post_meta( $post_id, PageAssets::META_JS, wp_json_encode( PageAssets::resolve_js_assets( $project_data ) ) );

		// Параллельный, не-манифестный путь для интерактивных "Элементов"
		// (countdown/counter/gallery/slider) — те же принципы: резолв один
		// раз при публикации, фронтенд просто читает postmeta.
		update_post_meta( $post_id, PageAssets::META_INTERACTIVE, PageAssets::has_interactive_elements( $project_data ) ? '1' : '' );

		/**
		 * Точка расширения для Phase 5 (каскадная инвалидация кэша всех
		 * страниц, затронутых публикацией части сайта) — сама по себе
		 * публикация уже полагается на штатные хуки wp_update_post()
		 * (save_post/clean_post_cache), это дополнительный, явный сигнал
		 * специфично для конструктора.
		 */
		do_action( 'wpgjb_after_publish', $post_id, $type );

		return array(
			'html'        => $html,
			'css'         => $sanitized['css'],
			'quarantined' => $quarantine_report,
		);
	}

}
