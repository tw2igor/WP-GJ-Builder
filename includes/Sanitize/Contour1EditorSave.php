<?php
/**
 * Контур 1 (раздел 10 спеки): срабатывает при КАЖДОМ сохранении в
 * редакторе (обычный автосейв/ручное сохранение черновика через
 * DocumentRepository::save_draft(), Phase 1). Это ТОНКАЯ обёртка —
 * никакой собственной логики санитизации здесь нет, только вызов
 * Sanitizer. Правила не дублируются и не переопределяются — см.
 * Sanitizer::sanitize_html()/sanitize_css() и Contour2PrePublish
 * (тот же вызов, другая точка срабатывания).
 */

namespace WPGJBuilder\Sanitize;

defined( 'ABSPATH' ) || exit;

class Contour1EditorSave {

	/**
	 * @param string $html                Сырой HTML слота/значения на момент сохранения.
	 * @param string $css                 Сырой CSS на момент сохранения.
	 * @param array  $allowed_tags        Whitelist тегов/атрибутов (формат wp_kses()).
	 * @param array  $allowed_css_properties Whitelist CSS-свойств.
	 * @return array{html: string, css: string}
	 */
	public static function sanitize_document( string $html, string $css, array $allowed_tags, array $allowed_css_properties ): array {
		return array(
			'html' => Sanitizer::sanitize_html( $html, $allowed_tags ),
			'css'  => Sanitizer::sanitize_css( $css, $allowed_css_properties ),
		);
	}
}
