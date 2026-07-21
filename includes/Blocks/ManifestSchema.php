<?php

namespace WPGJBuilder\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Словарь допустимых значений манифеста блока (раздел 9 спеки). Единый
 * контракт, который читают: форма "Контента" редактора (Phase 3),
 * серверная валидация при сохранении, и REST-эндпоинт вставки блока для
 * будущего AI-модуля (Phase 4) — один источник правды, не три реализации.
 */
class ManifestSchema {

	/**
	 * Фиксированный словарь типов секций (раздел 9: "из фиксированного
	 * словаря... свободные теги — дополнительно" — расширяемость даётся
	 * полем `tags`, а не расширением этого списка на лету).
	 */
	const SECTION_TYPES = array(
		'hero',
		'about',
		'features',
		'services',
		'pricing',
		'testimonials',
		'team',
		'gallery',
		'faq',
		'steps',
		'cta',
		'contacts',
		'header',
		'footer',
		'stats',
		'logos',
		'video',
		'misc',
	);

	/**
	 * Типы контентных слотов, которые умеет генерировать форма "Контента".
	 * `raw_html` — раздел 10: "спец-блок Вставка кода", единственный слот,
	 * который контур-1 пропускает БЕЗ санитизации, и только для
	 * пользователя с капабилити `wpgjb_insert_raw_code` (см.
	 * ProjectDataSanitizer) — не часть обычного набора типов для рядовых
	 * блоков, только для блока "Вставка кода" конкретно.
	 */
	const SLOT_TYPES = array( 'string', 'richtext', 'image', 'icon', 'link', 'array', 'raw_html' );

	/** Обязательные ключи манифеста верхнего уровня. */
	const REQUIRED_MANIFEST_KEYS = array( 'id', 'schema_version', 'section_type', 'purpose', 'slots' );

	/** Обязательные ключи одного слота. */
	const REQUIRED_SLOT_KEYS = array( 'key', 'type' );
}
