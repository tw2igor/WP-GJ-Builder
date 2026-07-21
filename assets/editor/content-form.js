/**
 * Форма "Контент" для манифест-управляемых "Блоков" — переиспользуемая
 * без изменений логика из прежней ручной правой панели (см. git-историю
 * index.js), теперь монтируется в `editor.Modal.open()` стокового
 * GrapesJS (grapesjs-preset-webpage), а не в свою кастомную панель.
 * Единственное отличие от прежней версии: `renderContentForm` принимает
 * контейнер параметром, а не читает фиксированный DOM id.
 */

import { __ } from '@wordpress/i18n';
import { extractValuesFromTemplate, getComponentValues, setComponentValues } from './blocks';
import { cacheFromMediaModel, getCachedAttachment, fetchAttachment } from './images';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

export function renderContentForm( component, blockDef, container, onChanged ) {
	const manifest = blockDef.manifest;
	const defaults = extractValuesFromTemplate( blockDef.markup, manifest );
	let values = { ...defaults, ...getComponentValues( component ) };

	container.innerHTML = '';
	manifest.slots.forEach( ( slot ) => {
		container.appendChild(
			renderSlotField( slot, values, ( newValues ) => {
				values = newValues;
				setComponentValues( component, blockDef, values );
				if ( 'function' === typeof onChanged ) {
					onChanged();
				}
			} )
		);
	} );
}

function renderSlotField( slot, values, onChange ) {
	const wrap = document.createElement( 'div' );
	wrap.className = 'wpgjb-field';

	const label = document.createElement( 'label' );
	label.textContent = slot.key + ( slot.required ? ' *' : '' );
	wrap.appendChild( label );

	if ( 'array' === slot.type ) {
		wrap.appendChild( renderArrayField( slot, values, onChange ) );
		return wrap;
	}

	if ( 'image' === slot.type ) {
		wrap.appendChild( renderImageField( slot, values, onChange ) );
		return wrap;
	}

	const isMultiline = 'richtext' === slot.type || 'raw_html' === slot.type;
	const input = isMultiline ? document.createElement( 'textarea' ) : document.createElement( 'input' );
	if ( 'raw_html' === slot.type ) {
		input.classList.add( 'wpgjb-field__code' );
	}
	if ( 'INPUT' === input.tagName ) {
		input.type = 'text';
	}
	if ( slot.max_length ) {
		input.maxLength = slot.max_length;
	}
	input.value = values[ slot.key ] || '';
	input.addEventListener( 'input', () => onChange( { ...values, [ slot.key ]: input.value } ) );
	wrap.appendChild( input );

	return wrap;
}

/**
 * Раздел 11: реальный медиа-пикер (wp.media) вместо ручного ввода ID
 * вложения. Значение слота по-прежнему сам ID (контракт с санитайзером/
 * REST не меняется) — но сразу после выбора кэшируем полную Backbone-
 * модель (images.js: cacheFromMediaModel), чтобы applyValues() применил
 * реальный src/srcset СИНХРОННО, без REST round-trip на уже выбранное
 * в этой же сессии изображение.
 */
function renderImageField( slot, values, onChange ) {
	const bootstrap = window.wpgjbEditorData;
	const container = document.createElement( 'div' );
	container.className = 'wpgjb-image-field';

	const currentId = values[ slot.key ] || '';
	const cached = currentId ? getCachedAttachment( currentId ) : null;

	const preview = document.createElement( 'img' );
	preview.className = 'wpgjb-image-field__preview';
	preview.hidden = ! cached;
	if ( cached ) {
		preview.src = cached.thumbnail;
	} else if ( currentId ) {
		// Значение уже выбрано (документ открыт заново/вставлен через REST),
		// но Backbone-модель wp.media в этой сессии ещё не кэширована —
		// догружаем превью через REST, как и канвас (images.js: fetchAttachment).
		fetchAttachment( currentId, bootstrap.restRoot, bootstrap.restNonce ).then( ( resolved ) => {
			if ( resolved ) {
				preview.src = resolved.thumbnail;
				preview.hidden = false;
			}
		} );
	}
	container.appendChild( preview );

	const pickBtn = document.createElement( 'button' );
	pickBtn.type = 'button';
	pickBtn.className = 'wpgjb-button wpgjb-button--ghost';
	pickBtn.textContent = currentId ? t( 'Заменить изображение' ) : t( 'Выбрать изображение' );
	container.appendChild( pickBtn );

	const removeBtn = document.createElement( 'button' );
	removeBtn.type = 'button';
	removeBtn.className = 'wpgjb-button wpgjb-button--ghost';
	removeBtn.textContent = t( 'Убрать' );
	removeBtn.hidden = ! currentId;
	container.appendChild( removeBtn );

	pickBtn.addEventListener( 'click', () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: t( 'Выбрать изображение' ),
			button: { text: t( 'Использовать' ) },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			const resolved = cacheFromMediaModel( attachment.id, attachment );
			preview.src = resolved.thumbnail;
			preview.hidden = false;
			pickBtn.textContent = t( 'Заменить изображение' );
			removeBtn.hidden = false;
			onChange( { ...values, [ slot.key ]: String( attachment.id ) } );
		} );
		frame.open();
	} );

	removeBtn.addEventListener( 'click', () => {
		preview.hidden = true;
		pickBtn.textContent = t( 'Выбрать изображение' );
		removeBtn.hidden = true;
		onChange( { ...values, [ slot.key ]: '' } );
	} );

	return container;
}

function renderArrayField( slot, values, onChange ) {
	const container = document.createElement( 'div' );
	container.className = 'wpgjb-array-field';

	const items = Array.isArray( values[ slot.key ] ) ? values[ slot.key ].slice() : [];
	const itemSlots = Object.keys( slot.item_schema ).map( ( key ) => ( { key, ...slot.item_schema[ key ] } ) );

	function commit() {
		onChange( { ...values, [ slot.key ]: items } );
	}

	function rerender() {
		container.innerHTML = '';

		items.forEach( ( itemValues, index ) => {
			const itemWrap = document.createElement( 'div' );
			itemWrap.className = 'wpgjb-array-item';

			itemSlots.forEach( ( itemSlot ) => {
				itemWrap.appendChild(
					renderSlotField( itemSlot, itemValues, ( newItemValues ) => {
						items[ index ] = newItemValues;
						commit();
					} )
				);
			} );

			const removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'wpgjb-button wpgjb-button--ghost wpgjb-array-item__remove';
			removeBtn.textContent = t( 'Удалить пункт' );
			removeBtn.addEventListener( 'click', () => {
				items.splice( index, 1 );
				commit();
				rerender();
			} );
			itemWrap.appendChild( removeBtn );

			container.appendChild( itemWrap );
		} );

		if ( ! slot.max_items || items.length < slot.max_items ) {
			const addBtn = document.createElement( 'button' );
			addBtn.type = 'button';
			addBtn.className = 'wpgjb-button wpgjb-button--ghost';
			addBtn.textContent = `+ ${ t( 'добавить карточку' ) }`;
			addBtn.addEventListener( 'click', () => {
				const blank = {};
				itemSlots.forEach( ( s ) => {
					blank[ s.key ] = '';
				} );
				items.push( blank );
				commit();
				rerender();
			} );
			container.appendChild( addBtn );
		}
	}

	rerender();
	return container;
}
