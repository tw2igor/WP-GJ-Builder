import { __ } from '@wordpress/i18n';
import { updatePageSettings } from './api';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

function renderFeaturedImageField( bootstrap ) {
	const container = document.createElement( 'div' );
	container.className = 'wpgjb-field';

	const label = document.createElement( 'label' );
	label.textContent = t( 'Картинка страницы' );
	container.appendChild( label );

	const imageField = document.createElement( 'div' );
	imageField.className = 'wpgjb-image-field';

	let currentId = bootstrap.featuredMedia || 0;

	const preview = document.createElement( 'img' );
	preview.className = 'wpgjb-image-field__preview';
	preview.hidden = ! bootstrap.featuredMediaUrl;
	if ( bootstrap.featuredMediaUrl ) {
		preview.src = bootstrap.featuredMediaUrl;
	}
	imageField.appendChild( preview );

	const pickBtn = document.createElement( 'button' );
	pickBtn.type = 'button';
	pickBtn.className = 'wpgjb-button wpgjb-button--ghost';
	pickBtn.textContent = currentId ? t( 'Заменить изображение' ) : t( 'Выбрать изображение' );
	imageField.appendChild( pickBtn );

	const removeBtn = document.createElement( 'button' );
	removeBtn.type = 'button';
	removeBtn.className = 'wpgjb-button wpgjb-button--ghost';
	removeBtn.textContent = t( 'Убрать' );
	removeBtn.hidden = ! currentId;
	imageField.appendChild( removeBtn );

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
			currentId = attachment.id;
			preview.src = attachment.url;
			preview.hidden = false;
			pickBtn.textContent = t( 'Заменить изображение' );
			removeBtn.hidden = false;
		} );
		frame.open();
	} );

	removeBtn.addEventListener( 'click', () => {
		currentId = 0;
		preview.hidden = true;
		pickBtn.textContent = t( 'Выбрать изображение' );
		removeBtn.hidden = true;
	} );

	container.appendChild( imageField );

	return { el: container, getValue: () => currentId };
}

export function openPageSettingsModal( editor, bootstrap, onSaved ) {
	const pageTemplates = bootstrap.pageTemplates;

	const body = document.createElement( 'div' );

	const titleWrap = document.createElement( 'div' );
	titleWrap.className = 'wpgjb-field';
	const titleLabel = document.createElement( 'label' );
	titleLabel.textContent = t( 'Заголовок страницы' );
	titleWrap.appendChild( titleLabel );
	const titleInput = document.createElement( 'input' );
	titleInput.type = 'text';
	titleInput.value = bootstrap.postTitle;
	titleWrap.appendChild( titleInput );
	body.appendChild( titleWrap );

	const slugWrap = document.createElement( 'div' );
	slugWrap.className = 'wpgjb-field';
	const slugLabel = document.createElement( 'label' );
	slugLabel.textContent = t( 'Slug (ЧПУ)' );
	slugWrap.appendChild( slugLabel );
	const slugInput = document.createElement( 'input' );
	slugInput.type = 'text';
	slugInput.value = bootstrap.postSlug || '';
	slugWrap.appendChild( slugInput );
	body.appendChild( slugWrap );

	const statusWrap = document.createElement( 'div' );
	statusWrap.className = 'wpgjb-field';
	const statusLabel = document.createElement( 'label' );
	statusLabel.textContent = t( 'Статус' );
	statusWrap.appendChild( statusLabel );
	const statusSelect = document.createElement( 'select' );
	[
		{ value: 'draft', label: t( 'Черновик' ) },
		{ value: 'publish', label: t( 'Опубликовано' ) },
	].forEach( ( { value, label } ) => {
		const option = document.createElement( 'option' );
		option.value = value;
		option.textContent = label;
		option.selected = value === bootstrap.postStatus;
		statusSelect.appendChild( option );
	} );
	statusWrap.appendChild( statusSelect );
	body.appendChild( statusWrap );

	const featuredImage = renderFeaturedImageField( bootstrap );
	body.appendChild( featuredImage.el );

	let templateSelect = null;
	if ( pageTemplates ) {
		const templateWrap = document.createElement( 'div' );
		templateWrap.className = 'wpgjb-field';
		const templateLabel = document.createElement( 'label' );
		templateLabel.textContent = t( 'Макет страницы' );
		templateWrap.appendChild( templateLabel );
		templateSelect = document.createElement( 'select' );
		Object.keys( pageTemplates.options ).forEach( ( file ) => {
			const option = document.createElement( 'option' );
			option.value = file;
			option.textContent = pageTemplates.options[ file ];
			option.selected = file === pageTemplates.current;
			templateSelect.appendChild( option );
		} );
		templateWrap.appendChild( templateSelect );
		body.appendChild( templateWrap );
	}

	const saveBtn = document.createElement( 'button' );
	saveBtn.type = 'button';
	saveBtn.className = 'wpgjb-button wpgjb-button--primary';
	saveBtn.textContent = t( 'Сохранить' );
	saveBtn.addEventListener( 'click', () => {
		updatePageSettings( {
			title: titleInput.value,
			slug: slugInput.value,
			status: statusSelect.value,
			featuredMedia: featuredImage.getValue(),
			pageTemplate: templateSelect ? templateSelect.value : undefined,
		} ).then( ( { status, data } ) => {
			if ( 200 === status && data && 'ok' === data.status ) {
				if ( 'function' === typeof onSaved ) {
					onSaved( {
						title: data.title,
						slug: data.slug,
						status: data.post_status,
						featuredMedia: data.featured_media,
						featuredMediaUrl: data.featured_media_url,
						pageTemplate: data.page_template,
					} );
				}
				editor.Modal.close();
			}
		} );
	} );
	body.appendChild( saveBtn );

	editor.Modal.open( { title: t( 'Настройки страницы' ), content: body } );
}
