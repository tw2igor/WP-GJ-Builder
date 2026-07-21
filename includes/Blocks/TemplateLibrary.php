<?php

namespace WPGJBuilder\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Загрузчик каталога готовых страниц из `templates-library/{id}/` —
 * третий уровень вложенности (Элементы → Блоки → Страницы, по прямому
 * требованию пользователя). Формат манифеста шаблона — `{ title, blocks:
 * [{block_id, slots}, ...] }` — ТА ЖЕ форма, что уже принимает
 * `POST /wpgjb/v1/pages/assemble` (BlocksCatalogController::assemble_page),
 * специально не изобретена параллельная схема: контракт должен остаться
 * REST-инспектируемым/собираемым и для будущего AI-модуля, а не стать
 * запечённым HTML без структуры.
 *
 * Как и BlockLibrary::all() — шаблон с невалидным member-блоком (не
 * существует в blocks-library, или его slots не проходят
 * ManifestValidator::validate_values()) не роняет весь каталог, просто
 * не попадает в него.
 */
class TemplateLibrary {

	/** @var array<string, array{title: string, blocks: array}>|null */
	private static $cache = null;

	/**
	 * @return array<string, array{title: string, blocks: array}>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::$cache = array();
		$root        = WPGJB_PLUGIN_DIR . 'templates-library';
		if ( ! is_dir( $root ) ) {
			return self::$cache;
		}

		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$template_id   = basename( $dir );
			$manifest_path = $dir . '/manifest.json';
			if ( ! file_exists( $manifest_path ) ) {
				continue;
			}

			$manifest = json_decode( file_get_contents( $manifest_path ), true );
			if ( ! self::is_valid_manifest( $manifest ) ) {
				continue;
			}

			self::$cache[ $template_id ] = array(
				'title'  => (string) $manifest['title'],
				'blocks' => $manifest['blocks'],
			);
		}

		return self::$cache;
	}

	public static function get( $template_id ) {
		$all = self::all();
		return isset( $all[ $template_id ] ) ? $all[ $template_id ] : null;
	}

	/**
	 * Валидирует и форму манифеста, и КАЖДЫЙ member-блок последовательности
	 * (block_id существует в BlockLibrary, slots проходят
	 * ManifestValidator::validate_values() для манифеста этого блока) —
	 * тот же рубеж, что assemble_page() применяет к запросу REST-клиента,
	 * здесь применяется к файлу на диске при загрузке каталога.
	 */
	private static function is_valid_manifest( $manifest ): bool {
		if ( ! is_array( $manifest ) || empty( $manifest['title'] ) || empty( $manifest['blocks'] ) || ! is_array( $manifest['blocks'] ) ) {
			return false;
		}

		foreach ( $manifest['blocks'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['block_id'] ) ) {
				return false;
			}

			$block = BlockLibrary::get( (string) $entry['block_id'] );
			if ( ! $block ) {
				return false;
			}

			$slots  = isset( $entry['slots'] ) && is_array( $entry['slots'] ) ? $entry['slots'] : array();
			$errors = ManifestValidator::validate_values( $block['manifest'], $slots );
			if ( ! empty( $errors ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Как all_visible_to_current_user() у BlockLibrary — шаблон,
	 * содержащий хотя бы один блок, требующий wpgjb_insert_raw_code,
	 * скрывается от пользователя без этой капабилити целиком (не только
	 * сам блок при вставке).
	 *
	 * @return array<string, array{title: string, blocks: array}>
	 */
	public static function all_visible_to_current_user(): array {
		$visible = array();
		foreach ( self::all() as $template_id => $template ) {
			$forbidden = false;
			foreach ( $template['blocks'] as $entry ) {
				$block = BlockLibrary::get( (string) $entry['block_id'] );
				if ( $block && BlockLibrary::requires_raw_code_capability( $block['manifest'] ) && ! current_user_can( 'wpgjb_insert_raw_code' ) ) {
					$forbidden = true;
					break;
				}
			}
			if ( ! $forbidden ) {
				$visible[ $template_id ] = $template;
			}
		}
		return $visible;
	}

	public static function reset_for_tests() {
		self::$cache = null;
	}
}
