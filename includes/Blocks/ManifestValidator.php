<?php

namespace WPGJBuilder\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Единственная реализация валидации манифеста блока и значений его слотов
 * (раздел 9 спеки: "манифест слотов — одновременно контракт для
 * AI-наполнения, валидация ввода человека и документация блока"). Форма
 * "Контента" редактора (Phase 3), сохранение блока человеком и будущий
 * REST-эндпоинт вставки блока для AI (Phase 4) обязаны вызывать ИМЕННО
 * эти методы — не переизобретать проверки на своей стороне.
 */
class ManifestValidator {

	/**
	 * Валидирует САМ манифест (форму блока), не значения его слотов.
	 *
	 * @return string[] Список ошибок; пустой массив — манифест валиден.
	 */
	public static function validate_manifest( array $manifest ): array {
		$errors = array();

		// Отсутствующее обязательное поле — своя ошибка; дальше проверяем
		// форму каждого поля НЕЗАВИСИМО (array_key_exists-гейт на каждый
		// блок), а не прерываем всю проверку на первом отсутствующем поле —
		// иначе манифест с несколькими независимыми проблемами показывает
		// только одну и заставляет чинить по одной ошибке за прогон.
		foreach ( ManifestSchema::REQUIRED_MANIFEST_KEYS as $key ) {
			if ( ! array_key_exists( $key, $manifest ) ) {
				$errors[] = "Отсутствует обязательное поле манифеста: \"{$key}\".";
			}
		}

		if ( array_key_exists( 'id', $manifest ) && ( ! is_string( $manifest['id'] ) || '' === trim( $manifest['id'] ) ) ) {
			$errors[] = 'Поле "id" должно быть непустой строкой.';
		}

		if ( array_key_exists( 'schema_version', $manifest ) && ( ! is_int( $manifest['schema_version'] ) || $manifest['schema_version'] < 1 ) ) {
			$errors[] = 'Поле "schema_version" должно быть целым числом >= 1.';
		}

		if ( array_key_exists( 'section_type', $manifest ) && ( ! is_string( $manifest['section_type'] ) || ! in_array( $manifest['section_type'], ManifestSchema::SECTION_TYPES, true ) ) ) {
			$errors[] = 'Поле "section_type" должно быть одним из: ' . implode( ', ', ManifestSchema::SECTION_TYPES ) . '.';
		}

		if ( array_key_exists( 'purpose', $manifest ) && ( ! is_string( $manifest['purpose'] ) || '' === trim( $manifest['purpose'] ) ) ) {
			$errors[] = 'Поле "purpose" должно быть непустой строкой.';
		}

		if ( isset( $manifest['tags'] ) ) {
			if ( ! self::is_list_of_strings( $manifest['tags'] ) ) {
				$errors[] = 'Поле "tags" должно быть массивом строк.';
			}
		}

		if ( array_key_exists( 'slots', $manifest ) ) {
			if ( ! is_array( $manifest['slots'] ) || empty( $manifest['slots'] ) ) {
				$errors[] = 'Поле "slots" должно быть непустым массивом.';
			} else {
				foreach ( $manifest['slots'] as $index => $slot ) {
					if ( ! is_array( $slot ) ) {
						$errors[] = "slots[{$index}] должен быть объектом.";
						continue;
					}
					$errors = array_merge( $errors, self::validate_slot_def( $slot, "slots[{$index}]" ) );
				}
			}
		}

		if ( isset( $manifest['context'] ) ) {
			$errors = array_merge( $errors, self::validate_context( $manifest['context'] ) );
		}

		if ( isset( $manifest['requirements'] ) ) {
			$errors = array_merge( $errors, self::validate_requirements( $manifest['requirements'] ) );
		}

		if ( isset( $manifest['style_whitelist'] ) && ! self::is_list_of_strings( $manifest['style_whitelist'] ) ) {
			$errors[] = 'Поле "style_whitelist" должно быть массивом строк (имена CSS-свойств).';
		}

		if ( isset( $manifest['layout_variants'] ) && ! self::is_list_of_strings( $manifest['layout_variants'] ) ) {
			$errors[] = 'Поле "layout_variants" должно быть массивом строк.';
		}

		return $errors;
	}

	private static function validate_slot_def( array $slot, string $path ): array {
		$errors = array();

		foreach ( ManifestSchema::REQUIRED_SLOT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $slot ) ) {
				$errors[] = "{$path}: отсутствует обязательное поле \"{$key}\".";
			}
		}
		if ( ! empty( $errors ) ) {
			return $errors;
		}

		if ( ! is_string( $slot['key'] ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $slot['key'] ) ) {
			$errors[] = "{$path}: \"key\" должен быть строкой в формате snake_case, начинающейся с буквы.";
		}

		if ( ! is_string( $slot['type'] ) || ! in_array( $slot['type'], ManifestSchema::SLOT_TYPES, true ) ) {
			$errors[] = "{$path}: \"type\" должен быть одним из: " . implode( ', ', ManifestSchema::SLOT_TYPES ) . '.';
			return $errors; // Дальнейшие проверки зависят от типа, который некорректен.
		}

		switch ( $slot['type'] ) {
			case 'string':
			case 'richtext':
				if ( isset( $slot['max_length'] ) && ( ! is_int( $slot['max_length'] ) || $slot['max_length'] < 1 ) ) {
					$errors[] = "{$path}: \"max_length\" должен быть целым числом >= 1.";
				}
				break;

			case 'image':
				if ( isset( $slot['orientation'] ) && ! in_array( $slot['orientation'], array( 'landscape', 'portrait', 'square' ), true ) ) {
					$errors[] = "{$path}: \"orientation\" должен быть landscape, portrait или square.";
				}
				if ( isset( $slot['min_width'] ) && ( ! is_int( $slot['min_width'] ) || $slot['min_width'] < 1 ) ) {
					$errors[] = "{$path}: \"min_width\" должен быть целым числом >= 1.";
				}
				if ( isset( $slot['first_screen'] ) && ! is_bool( $slot['first_screen'] ) ) {
					$errors[] = "{$path}: \"first_screen\" должен быть булевым значением.";
				}
				break;

			case 'array':
				if ( ! isset( $slot['item_schema'] ) || ! is_array( $slot['item_schema'] ) ) {
					$errors[] = "{$path}: слот типа \"array\" обязан иметь \"item_schema\" (объект вложенных слотов).";
					break;
				}
				foreach ( $slot['item_schema'] as $item_key => $item_slot ) {
					if ( ! is_array( $item_slot ) ) {
						$errors[] = "{$path}.item_schema.{$item_key} должен быть объектом.";
						continue;
					}
					// Вложенный слот описывается без "key" (ключ — это сам $item_key).
					$item_slot['key'] = is_string( $item_key ) ? $item_key : ( $item_slot['key'] ?? 'item' );
					$errors           = array_merge( $errors, self::validate_slot_def( $item_slot, "{$path}.item_schema.{$item_key}" ) );
				}
				if ( isset( $slot['min_items'] ) && ( ! is_int( $slot['min_items'] ) || $slot['min_items'] < 0 ) ) {
					$errors[] = "{$path}: \"min_items\" должен быть целым числом >= 0.";
				}
				if ( isset( $slot['max_items'] ) && ( ! is_int( $slot['max_items'] ) || $slot['max_items'] < 1 ) ) {
					$errors[] = "{$path}: \"max_items\" должен быть целым числом >= 1.";
				}
				if ( isset( $slot['min_items'], $slot['max_items'] ) && $slot['min_items'] > $slot['max_items'] ) {
					$errors[] = "{$path}: \"min_items\" не может быть больше \"max_items\".";
				}
				break;
		}

		return $errors;
	}

	private static function validate_context( $context ): array {
		if ( ! is_array( $context ) ) {
			return array( 'Поле "context" должно быть объектом.' );
		}
		$errors = array();
		if ( isset( $context['recommended_after'] ) && ! self::is_list_of_strings( $context['recommended_after'] ) ) {
			$errors[] = 'context.recommended_after должен быть массивом строк.';
		}
		if ( isset( $context['allow_repeat'] ) && ! is_bool( $context['allow_repeat'] ) ) {
			$errors[] = 'context.allow_repeat должен быть булевым значением.';
		}
		if ( isset( $context['niches'] ) && ! self::is_list_of_strings( $context['niches'] ) ) {
			$errors[] = 'context.niches должен быть массивом строк.';
		}
		return $errors;
	}

	private static function validate_requirements( $requirements ): array {
		if ( ! is_array( $requirements ) ) {
			return array( 'Поле "requirements" должно быть объектом.' );
		}
		$errors = array();
		if ( isset( $requirements['dynamic_tags'] ) && ! self::is_list_of_strings( $requirements['dynamic_tags'] ) ) {
			$errors[] = 'requirements.dynamic_tags должен быть массивом строк.';
		}
		if ( isset( $requirements['assets'] ) ) {
			if ( ! is_array( $requirements['assets'] ) ) {
				$errors[] = 'requirements.assets должен быть объектом.';
			} else {
				foreach ( array( 'js', 'css' ) as $group ) {
					if ( isset( $requirements['assets'][ $group ] ) && ! self::is_list_of_strings( $requirements['assets'][ $group ] ) ) {
						$errors[] = "requirements.assets.{$group} должен быть массивом строк.";
					}
				}
			}
		}
		return $errors;
	}

	/**
	 * Валидирует ЗНАЧЕНИЯ слотов (реальный контент), поданные человеком
	 * через форму "Контента" ИЛИ AI-модулем через REST — один и тот же
	 * метод для обоих путей (раздел 9: "один манифест, три потребителя").
	 * Предполагает, что сам манифест уже прошёл validate_manifest().
	 *
	 * @param array $manifest Валидный манифест блока.
	 * @param array $values   Ассоциативный массив key => значение.
	 * @return string[] Список ошибок; пустой массив — значения валидны.
	 */
	public static function validate_values( array $manifest, array $values ): array {
		return self::validate_values_against_slots( $manifest['slots'], $values, '' );
	}

	private static function validate_values_against_slots( array $slots, array $values, string $path ): array {
		$errors = array();

		foreach ( $slots as $slot ) {
			$key       = $slot['key'];
			$slot_path = '' === $path ? $key : "{$path}.{$key}";
			$required  = ! empty( $slot['required'] );

			if ( ! array_key_exists( $key, $values ) || null === $values[ $key ] ) {
				if ( $required ) {
					$errors[] = "{$slot_path}: обязательное поле не заполнено.";
				}
				continue;
			}

			$value = $values[ $key ];

			switch ( $slot['type'] ) {
				case 'string':
				case 'richtext':
				case 'raw_html':
					// raw_html: структурная проверка та же (строка,
					// опциональная максимальная длина) — вопрос "разрешено ли
					// вообще пропустить это без санитизации" решает
					// ProjectDataSanitizer по капабилити, не валидатор здесь.
					if ( ! is_string( $value ) ) {
						$errors[] = "{$slot_path}: ожидалась строка.";
						break;
					}
					if ( isset( $slot['max_length'] ) && function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $slot['max_length'] ) {
						$errors[] = "{$slot_path}: превышена максимальная длина {$slot['max_length']} символов.";
					}
					break;

				case 'link':
				case 'icon':
					if ( ! is_string( $value ) || '' === trim( $value ) ) {
						$errors[] = "{$slot_path}: ожидалась непустая строка.";
					}
					break;

				case 'image':
					if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
						$errors[] = "{$slot_path}: ожидался ID вложения (число).";
					}
					break;

				case 'array':
					if ( ! is_array( $value ) ) {
						$errors[] = "{$slot_path}: ожидался массив элементов.";
						break;
					}
					if ( isset( $slot['min_items'] ) && count( $value ) < $slot['min_items'] ) {
						$errors[] = "{$slot_path}: минимум элементов — {$slot['min_items']}.";
					}
					if ( isset( $slot['max_items'] ) && count( $value ) > $slot['max_items'] ) {
						$errors[] = "{$slot_path}: максимум элементов — {$slot['max_items']}.";
					}

					$item_slots = array();
					foreach ( $slot['item_schema'] as $item_key => $item_slot ) {
						$item_slot['key'] = is_string( $item_key ) ? $item_key : ( $item_slot['key'] ?? 'item' );
						$item_slots[]     = $item_slot;
					}

					foreach ( $value as $index => $item_values ) {
						if ( ! is_array( $item_values ) ) {
							$errors[] = "{$slot_path}[{$index}]: ожидался объект значений элемента.";
							continue;
						}
						$errors = array_merge(
							$errors,
							self::validate_values_against_slots( $item_slots, $item_values, "{$slot_path}[{$index}]" )
						);
					}
					break;
			}
		}

		return $errors;
	}

	private static function is_list_of_strings( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}
}
