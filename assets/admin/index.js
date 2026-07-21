/**
 * Точка входа админ-экранов вне полноэкранного редактора (раздел 4 спеки).
 * Единственный потребитель на сегодня: модалка "Создать страницу с AI"
 * на экране "Страницы" (раздел 9 спеки, AI-фаза) — кнопка рендерится
 * сервером (`EditorPage::render_ai_generate_button()`), бутстрап-данные
 * приходят через `window.wpgjbAiGenerator` (см.
 * `EditorPage::maybe_enqueue_ai_admin_assets()`). Если ни кнопки, ни
 * бутстрапа нет на странице — скрипт просто ничего не делает.
 */

import { __ } from '@wordpress/i18n';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

const OTHER_NICHE = 'Другое';

const NICHE_OPTIONS = [
	'Кофейня',
	'Ресторан',
	'Салон красоты',
	'Фитнес-клуб',
	'Юридические услуги',
	'IT-услуги/разработка',
	'Ремонт и строительство',
	'Автосервис',
	OTHER_NICHE,
];
const PAGE_TYPE_OPTIONS = [ 'Лендинг', 'О компании', 'Услуги', 'Контакты', 'Портфолио' ];
const TONE_OPTIONS = [ 'Дружелюбный', 'Профессиональный', 'Продающий', 'Экспертный' ];

function buildField( id, labelText, fieldEl ) {
	const wrap = document.createElement( 'div' );
	wrap.style.marginBottom = '12px';
	const label = document.createElement( 'label' );
	label.textContent = labelText;
	label.htmlFor = id;
	label.style.cssText = 'display:block;font-weight:600;margin-bottom:4px;';
	fieldEl.id = id;
	fieldEl.style.width = '100%';
	wrap.appendChild( label );
	wrap.appendChild( fieldEl );
	return wrap;
}

function buildSelect( id, labelText, options ) {
	const select = document.createElement( 'select' );
	options.forEach( ( opt ) => {
		const option = document.createElement( 'option' );
		option.value = opt;
		option.textContent = opt;
		select.appendChild( option );
	} );
	return { wrap: buildField( id, labelText, select ), select };
}

function openModal( bootstrap ) {
	const overlay = document.createElement( 'div' );
	overlay.style.cssText =
		'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center;';

	const modal = document.createElement( 'div' );
	modal.style.cssText = 'background:#fff;border-radius:6px;padding:24px;width:420px;max-width:90vw;max-height:85vh;overflow:auto;';

	const title = document.createElement( 'h2' );
	title.textContent = t( 'Создать страницу с AI' );
	title.style.marginTop = '0';
	modal.appendChild( title );

	const niche = buildSelect( 'wpgjb-ai-niche', t( 'Ниша' ), NICHE_OPTIONS );
	const pageType = buildSelect( 'wpgjb-ai-page-type', t( 'Тип страницы' ), PAGE_TYPE_OPTIONS );
	const tone = buildSelect( 'wpgjb-ai-tone', t( 'Тон' ), TONE_OPTIONS );
	modal.appendChild( niche.wrap );

	const otherNicheInput = document.createElement( 'input' );
	otherNicheInput.type = 'text';
	otherNicheInput.placeholder = t( 'Опишите нишу' );
	const otherNicheWrap = buildField( 'wpgjb-ai-niche-other', t( 'Своя ниша' ), otherNicheInput );
	otherNicheWrap.hidden = true;
	modal.appendChild( otherNicheWrap );

	niche.select.addEventListener( 'change', () => {
		otherNicheWrap.hidden = OTHER_NICHE !== niche.select.value;
	} );

	modal.appendChild( pageType.wrap );
	modal.appendChild( tone.wrap );

	const detailsInput = document.createElement( 'textarea' );
	detailsInput.rows = 3;
	modal.appendChild( buildField( 'wpgjb-ai-details', t( 'Детали (необязательно)' ), detailsInput ) );

	const errorBox = document.createElement( 'div' );
	errorBox.style.cssText = 'color:#b32d2e;margin-bottom:12px;display:none;';
	modal.appendChild( errorBox );

	const actions = document.createElement( 'div' );
	actions.style.cssText = 'display:flex;gap:8px;justify-content:flex-end;';

	const cancelBtn = document.createElement( 'button' );
	cancelBtn.type = 'button';
	cancelBtn.className = 'button';
	cancelBtn.textContent = t( 'Отмена' );
	cancelBtn.addEventListener( 'click', () => overlay.remove() );

	const submitBtn = document.createElement( 'button' );
	submitBtn.type = 'button';
	submitBtn.className = 'button button-primary';
	submitBtn.textContent = t( 'Сгенерировать' );

	actions.appendChild( cancelBtn );
	actions.appendChild( submitBtn );
	modal.appendChild( actions );

	overlay.appendChild( modal );
	document.body.appendChild( overlay );

	function setBusy( busy ) {
		submitBtn.disabled = busy;
		submitBtn.textContent = busy ? t( 'Генерируем…' ) : t( 'Сгенерировать' );
	}

	function showError( message ) {
		errorBox.textContent = message;
		errorBox.style.display = 'block';
	}

	submitBtn.addEventListener( 'click', () => {
		const nicheValue = OTHER_NICHE === niche.select.value ? otherNicheInput.value.trim() : niche.select.value;

		errorBox.style.display = 'none';
		setBusy( true );

		fetch( `${ bootstrap.restRoot }${ bootstrap.namespace }/ai/generate-page`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': bootstrap.restNonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				niche: nicheValue,
				page_type: pageType.select.value,
				tone: tone.select.value,
				details: detailsInput.value,
			} ),
		} )
			.then( ( res ) => res.json().then( ( data ) => ( { status: res.status, data } ) ) )
			.then( ( { status, data } ) => {
				if ( 201 === status && data && 'ok' === data.status ) {
					window.location.href = data.editor_url;
					return;
				}
				const errors = ( data && data.errors ) || [ t( 'Не удалось создать страницу.' ) ];
				showError( errors.map( ( e ) => ( 'string' === typeof e ? e : JSON.stringify( e ) ) ).join( '; ' ) );
				setBusy( false );
			} )
			.catch( () => {
				showError( t( 'Ошибка сети. Попробуйте ещё раз.' ) );
				setBusy( false );
			} );
	} );
}

function initAiGenerator() {
	const btn = document.getElementById( 'wpgjb-ai-generate-btn' );
	const bootstrap = window.wpgjbAiGenerator;
	if ( ! btn || ! bootstrap ) {
		return;
	}
	btn.addEventListener( 'click', () => openModal( bootstrap ) );
}

initAiGenerator();
