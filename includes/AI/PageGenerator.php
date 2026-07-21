<?php

namespace WPGJBuilder\AI;

use WPGJBuilder\Blocks\BlockLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Оркестрация "AI собирает страницу" (раздел 9 спеки, принцип П7:
 * "будущий AI-модуль собирает страницы теми же операциями, что и
 * человек"). Вся работа этого класса — (1) построить промпт, (2) вызвать
 * `TwcAiClient`, (3) распарсить JSON, (4) отдать его во ВНУТРЕННИЙ REST-
 * диспетч `POST /wpgjb/v1/pages/assemble` через `rest_do_request()` — тот
 * же самый код (валидация/санитизация/рендер/атомарность), что и ручная
 * сборка, не копия правил. При невалидном ответе (битый JSON или 422 с
 * ошибками по индексам) — один раунд самокоррекции: точный список ошибок
 * обратно модели, повтор не более 1 раза (итого максимум `MAX_ATTEMPTS`
 * попыток).
 */
class PageGenerator {

	const MAX_ATTEMPTS = 2;

	/**
	 * @param array $brief {niche?, page_type?, tone?, details?}
	 * @return array{status:string, post_id?:int, editor_url?:string, errors?:array}
	 */
	public static function generate( array $brief ): array {
		$messages = array(
			array( 'role' => 'system', 'content' => self::system_prompt() ),
			array( 'role' => 'user', 'content' => self::user_prompt( $brief ) ),
		);

		$last_errors = array( __( 'Не удалось получить ответ от AI.', 'wp-gj-builder' ) );

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			AiRequestLogger::log_request( $attempt, self::MAX_ATTEMPTS, $brief, $messages );

			$reply = TwcAiClient::chat_completion( $messages );

			if ( is_wp_error( $reply ) ) {
				AiRequestLogger::log_response( $reply, null );
				return array(
					'status' => 'error',
					'errors' => array( $reply->get_error_message() ),
				);
			}

			$parsed = self::parse_json_payload( $reply );

			if ( null === $parsed ) {
				AiRequestLogger::log_response( $reply, null );
				$last_errors = array( __( 'Модель вернула невалидный JSON.', 'wp-gj-builder' ) );
				if ( $attempt < self::MAX_ATTEMPTS ) {
					$messages[] = array( 'role' => 'assistant', 'content' => $reply );
					$messages[] = array(
						'role'    => 'user',
						'content' => __( 'Твой предыдущий ответ не является корректным JSON нужного формата. Верни ТОЛЬКО валидный JSON — без markdown-разметки, без пояснений, точно в формате {"title": "...", "blocks": [...]}.', 'wp-gj-builder' ),
					);
				}
				continue;
			}

			$assembled = self::dispatch_assemble( $parsed );
			AiRequestLogger::log_response( $reply, $assembled );

			if ( 201 === $assembled['status'] ) {
				$data = $assembled['data'];
				return array(
					'status'     => 'ok',
					'post_id'    => $data['post_id'],
					'editor_url' => $data['editor_url'],
				);
			}

			$last_errors = self::extract_errors( $assembled['data'] );

			if ( $attempt < self::MAX_ATTEMPTS ) {
				$messages[] = array( 'role' => 'assistant', 'content' => $reply );
				$messages[] = array(
					'role'    => 'user',
					'content' => self::correction_prompt( $last_errors ),
				);
			}
		}

		return array(
			'status' => 'error',
			'errors' => $last_errors,
		);
	}

	/**
	 * `assemble_page()` сигнализирует 422 двумя разными путями: пустой
	 * список блоков — через `WP_Error` (REST-фреймворк заворачивает его в
	 * `{code, message, data:{status}}`), невалидные элементы
	 * последовательности — напрямую `WP_REST_Response{status:'invalid',
	 * errors:[{index,block_id,errors}]}`. Учитываем оба варианта, а не
	 * только один.
	 */
	private static function extract_errors( $data ): array {
		if ( is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			return $data['errors'];
		}
		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			return array( $data['message'] );
		}
		return array( __( 'Не удалось собрать страницу.', 'wp-gj-builder' ) );
	}

	private static function system_prompt(): string {
		$catalog = wp_json_encode( BlockLibrary::export_ai_digest(), JSON_UNESCAPED_UNICODE );

		return implode(
			"\n\n",
			array(
				'Ты — ассистент по сборке страниц для WordPress-конструктора сайтов. Твоя задача — выбрать подходящие блоки из ПРЕДОСТАВЛЕННОГО ниже каталога и заполнить их текстовые поля (слоты) содержимым под описанный бизнес.',
				implode(
					"\n",
					array(
						'СТРОГИЕ ПРАВИЛА:',
						'1. Отвечай ТОЛЬКО одним JSON-объектом, без markdown-разметки (без ```), без пояснений до или после. Формат ровно такой: {"title": "заголовок страницы", "blocks": [{"block_id": "id-блока-из-каталога", "slots": {"ключ_слота": "значение"}}, ...]}.',
						'2. Используй ТОЛЬКО block_id из каталога ниже. Никогда не изобретай свои block_id.',
						'3. Для слота с типом "image" — НЕ включай этот ключ в slots вообще (у тебя нет доступа к медиатеке, любое значение всё равно будет проигнорировано).',
						'4. Для слота с типом "link" — используй "#" как значение (реальные адреса других страниц сайта тебе неизвестны).',
						'5. Для слота с типом "icon" — используй один короткий эмодзи-символ, отражающий смысл (например "🚀", "💡", "📞").',
						'6. Для слота с типом "array" — верни массив объектов по схеме item_schema этого слота, с количеством элементов между min_items и max_items включительно (оба указаны в каталоге у каждого такого слота).',
						'7. Уважай max_length у строковых слотов — не превышай указанное число символов.',
						'8. Пиши по-русски, если явно не попросили другой язык.',
					)
				),
				'Каталог доступных блоков (JSON, каждый со своими слотами и их ограничениями):',
				$catalog,
			)
		);
	}

	private static function user_prompt( array $brief ): string {
		$lines = array();
		if ( ! empty( $brief['niche'] ) ) {
			$lines[] = sprintf( 'Ниша/сфера бизнеса: %s', $brief['niche'] );
		}
		if ( ! empty( $brief['page_type'] ) ) {
			$lines[] = sprintf( 'Тип страницы: %s', $brief['page_type'] );
		}
		if ( ! empty( $brief['tone'] ) ) {
			$lines[] = sprintf( 'Тон текста: %s', $brief['tone'] );
		}
		if ( ! empty( $brief['details'] ) ) {
			$lines[] = sprintf( 'Дополнительные детали: %s', $brief['details'] );
		}
		$lines[] = 'Собери страницу из подходящих блоков каталога и заполни их слоты содержимым под это описание.';

		return implode( "\n", $lines );
	}

	private static function correction_prompt( array $errors ): string {
		return implode(
			"\n",
			array(
				'Твой предыдущий JSON не прошёл проверку. Ошибки (индекс элемента в "blocks" -> список проблем):',
				wp_json_encode( $errors, JSON_UNESCAPED_UNICODE ),
				'Верни ИСПРАВЛЕННЫЙ ПОЛНЫЙ JSON целиком (не только исправленную часть), в том же формате {"title", "blocks"}.',
			)
		);
	}

	/** @return array{title:string, blocks:array}|null null, если это не тот формат / невалидный JSON. */
	private static function parse_json_payload( string $raw ) {
		$clean = trim( $raw );
		// Модели иногда оборачивают ответ в markdown-код-блок несмотря на
		// явный запрет в промпте — снимаем ```json/``` по краям, если есть.
		$clean = (string) preg_replace( '/^```(?:json)?\s*/i', '', $clean );
		$clean = (string) preg_replace( '/\s*```\s*$/', '', $clean );

		$data = json_decode( $clean, true );
		if ( ! is_array( $data ) || empty( $data['title'] ) || ! isset( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
			return null;
		}
		return $data;
	}

	/** @return array{status:int, data:mixed} */
	private static function dispatch_assemble( array $payload ): array {
		$request = new \WP_REST_Request( 'POST', '/wpgjb/v1/pages/assemble' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		$response = rest_do_request( $request );

		return array(
			'status' => $response->get_status(),
			'data'   => $response->get_data(),
		);
	}
}
