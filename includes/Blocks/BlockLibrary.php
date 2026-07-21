<?php

namespace WPGJBuilder\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Загрузчик каталога платформенных блоков из `blocks-library/{id}/`
 * (раздел 9 спеки). Единый источник для: бутстрапа редактора (Phase 3),
 * будущего REST-каталога (Phase 4). Манифест обязателен и обязан пройти
 * ManifestValidator; markup/style — по возможности.
 */
class BlockLibrary {

	/** @var array<string, array{manifest: array, markup: string, style: string}>|null */
	private static $cache = null;

	/**
	 * @return array<string, array{manifest: array, markup: string, style: string}>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::$cache = array();
		$root        = WPGJB_PLUGIN_DIR . 'blocks-library';
		if ( ! is_dir( $root ) ) {
			return self::$cache;
		}

		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$block_id     = basename( $dir );
			$manifest_path = $dir . '/manifest.json';
			if ( ! file_exists( $manifest_path ) ) {
				continue;
			}

			$manifest = json_decode( file_get_contents( $manifest_path ), true );
			if ( ! is_array( $manifest ) ) {
				continue;
			}

			$errors = ManifestValidator::validate_manifest( $manifest );
			if ( ! empty( $errors ) ) {
				continue; // Блок с невалидным манифестом не попадает в каталог.
			}

			$markup_path = $dir . '/markup.html';
			$style_path  = $dir . '/style.css';

			self::$cache[ $block_id ] = array(
				'manifest' => $manifest,
				'markup'   => file_exists( $markup_path ) ? file_get_contents( $markup_path ) : '',
				'style'    => file_exists( $style_path ) ? file_get_contents( $style_path ) : '',
			);
		}

		return self::$cache;
	}

	public static function get( $block_id ) {
		$all = self::all();
		return isset( $all[ $block_id ] ) ? $all[ $block_id ] : null;
	}

	/**
	 * Раздел 10: блоки со слотом raw_html ("Вставка кода") видны и
	 * доступны для вставки ТОЛЬКО пользователям с капабилити
	 * `wpgjb_insert_raw_code` — непривилегированный пользователь не
	 * должен даже узнать об их существовании через каталог, не только
	 * не суметь вставить код (санитизация в ProjectDataSanitizer — это
	 * второй, независимый рубеж защиты, не единственный).
	 */
	public static function requires_raw_code_capability( array $manifest ): bool {
		foreach ( $manifest['slots'] as $slot ) {
			if ( 'raw_html' === ( $slot['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Как all(), но отфильтровано под права ТЕКУЩЕГО пользователя.
	 *
	 * @return array<string, array{manifest: array, markup: string, style: string}>
	 */
	public static function all_visible_to_current_user(): array {
		$visible = array();
		foreach ( self::all() as $block_id => $block ) {
			if ( self::requires_raw_code_capability( $block['manifest'] ) && ! current_user_can( 'wpgjb_insert_raw_code' ) ) {
				continue;
			}
			$visible[ $block_id ] = $block;
		}
		return $visible;
	}

	/**
	 * Компактная проекция каталога под LLM-промпт (раздел 9: "манифест
	 * слотов — одновременно контракт для AI-наполнения"). Только то, что
	 * реально нужно модели, чтобы выбрать блоки и заполнить их слоты:
	 * `id`/`section_type`/`purpose` (естественный язык — по этому полю
	 * модель и решает, уместен ли блок) и сама грамматика слотов
	 * (ключ/тип/ограничения) — БЕЗ markup/style/context/requirements/
	 * style_whitelist, они модели не нужны и только тратят токены
	 * промпта. `all_visible_to_current_user()`, а не `all()` — блоки,
	 * требующие `wpgjb_insert_raw_code`, не должны попадать в промпт для
	 * пользователя без этого права (то же правило, что уже действует для
	 * REST-каталога).
	 *
	 * @return array<int, array{id: string, section_type: string, purpose: string, slots: array}>
	 */
	public static function export_ai_digest(): array {
		$digest = array();
		foreach ( self::all_visible_to_current_user() as $block_id => $block ) {
			$manifest = $block['manifest'];
			$digest[] = array(
				'id'           => $block_id,
				'section_type' => $manifest['section_type'] ?? '',
				'purpose'      => $manifest['purpose'] ?? '',
				'slots'        => $manifest['slots'] ?? array(),
			);
		}
		return $digest;
	}

	public static function reset_for_tests() {
		self::$cache = null;
	}
}
