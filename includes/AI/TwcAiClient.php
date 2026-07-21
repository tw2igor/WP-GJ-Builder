<?php

namespace WPGJBuilder\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Тонкая обёртка над Timeweb Cloud AI Agent API (`docs/TWC AI API.md`) —
 * OpenAI-совместимый `/v1/chat/completions`. ПЕРВАЯ исходящая HTTP-
 * интеграция в этом плагине — прецедента в коде нет, ориентируемся на
 * то, как REST-контроллеры уже возвращают структурированные ошибки
 * (`WP_Error`), а не изобретаем новый паттерн.
 *
 * Документация прямо говорит, что `model`/`response_format` игнорируются
 * агентом ("This field is ignored as the agent has its own model
 * configuration"), и не предлагает JSON-schema/structured-output режим —
 * единственный документированный `response_format.type` — `text`. Значит,
 * единственный способ получить строго типизированный JSON — попросить об
 * этом в самом промпте и распарсить ответ на своей стороне (см.
 * `PageGenerator` — цикл самокоррекции рассчитан именно на этот случай).
 */
class TwcAiClient {

	const OPTION_AGENT_ACCESS_ID = 'wpgjb_ai_agent_access_id';
	const OPTION_BEARER_TOKEN    = 'wpgjb_ai_bearer_token';

	const BASE_URL = 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/';

	const TIMEOUT_SECONDS = 60;

	public static function is_configured(): bool {
		return '' !== self::agent_access_id() && '' !== self::bearer_token();
	}

	public static function agent_access_id(): string {
		return (string) get_option( self::OPTION_AGENT_ACCESS_ID, '' );
	}

	public static function bearer_token(): string {
		return (string) get_option( self::OPTION_BEARER_TOKEN, '' );
	}

	/**
	 * @param array $messages Формат OpenAI chat messages: [{role, content}, ...].
	 * @return string|\WP_Error Текст ответа модели (choices[0].message.content) либо ошибка.
	 */
	public static function chat_completion( array $messages ) {
		if ( ! self::is_configured() ) {
			return new \WP_Error(
				'wpgjb_ai_not_configured',
				__( 'Не заданы учётные данные AI (Agent Access ID / Bearer Token) — задайте их в Настройках конструктора.', 'wp-gj-builder' )
			);
		}

		$url = self::BASE_URL . rawurlencode( self::agent_access_id() ) . '/v1/chat/completions';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::bearer_token(),
					// Пример в документации сам передаёт пустое значение —
					// назначение заголовка на стороне Timeweb нигде не описано.
					'x-proxy-source' => '',
					'Content-Type'   => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						// Игнорируется агентом (см. документацию) — просто
						// заполняем формально обязательное поле схемы.
						'model'    => 'gpt-4',
						'messages' => $messages,
						'stream'   => false,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'wpgjb_ai_http_error',
				self::extract_error_message( $body, $code ),
				array( 'status' => $code )
			);
		}

		$content = $body['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error( 'wpgjb_ai_empty_response', __( 'TWC AI вернул пустой ответ.', 'wp-gj-builder' ) );
		}

		return $content;
	}

	private static function extract_error_message( $body, int $code ): string {
		if ( is_array( $body ) && isset( $body['error'] ) ) {
			if ( is_array( $body['error'] ) && ! empty( $body['error']['message'] ) ) {
				return (string) $body['error']['message'];
			}
			if ( is_string( $body['error'] ) ) {
				return $body['error'];
			}
		}
		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'TWC AI вернул ошибку (HTTP %d).', 'wp-gj-builder' ),
			$code
		);
	}
}
