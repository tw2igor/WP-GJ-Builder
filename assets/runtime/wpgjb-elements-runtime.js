/**
 * Общий фронтенд-рантайм для интерактивных "Элементов" (обратный отсчёт,
 * счётчик, галерея, слайдер) — подключается ТОЛЬКО когда на странице
 * реально есть хотя бы один такой элемент (см.
 * PageAssets::has_interactive_elements()/FrontendRenderer::enqueue_page_assets()).
 * Зависит от wpgjb-runtime.js (WPGJB.onDelegate) — тот же принцип
 * событийной делегации, что и у блок-специфичных runtime-скриптов.
 */
( function () {
	'use strict';

	function initCountdowns() {
		var els = document.querySelectorAll( '[data-wpgjb-countdown]' );
		els.forEach( function ( el ) {
			var targetAttr = el.getAttribute( 'data-wpgjb-countdown-target' );
			if ( ! targetAttr ) {
				return;
			}
			var target = new Date( targetAttr ).getTime();
			if ( isNaN( target ) ) {
				return;
			}
			var unitEls = {
				days: el.querySelector( '[data-wpgjb-countdown-unit="days"]' ),
				hours: el.querySelector( '[data-wpgjb-countdown-unit="hours"]' ),
				minutes: el.querySelector( '[data-wpgjb-countdown-unit="minutes"]' ),
				seconds: el.querySelector( '[data-wpgjb-countdown-unit="seconds"]' ),
			};

			function pad( n ) {
				return String( n ).padStart( 2, '0' );
			}

			function tick() {
				var diff = target - Date.now();
				if ( diff <= 0 ) {
					Object.keys( unitEls ).forEach( function ( key ) {
						if ( unitEls[ key ] ) {
							unitEls[ key ].textContent = '00';
						}
					} );
					clearInterval( timer );
					return;
				}
				var seconds = Math.floor( diff / 1000 );
				var days = Math.floor( seconds / 86400 );
				seconds -= days * 86400;
				var hours = Math.floor( seconds / 3600 );
				seconds -= hours * 3600;
				var minutes = Math.floor( seconds / 60 );
				seconds -= minutes * 60;
				if ( unitEls.days ) {
					unitEls.days.textContent = pad( days );
				}
				if ( unitEls.hours ) {
					unitEls.hours.textContent = pad( hours );
				}
				if ( unitEls.minutes ) {
					unitEls.minutes.textContent = pad( minutes );
				}
				if ( unitEls.seconds ) {
					unitEls.seconds.textContent = pad( seconds );
				}
			}

			var timer = setInterval( tick, 1000 );
			tick();
		} );
	}

	function animateCounter( el ) {
		var valueEl = el.querySelector( '[data-wpgjb-counter-value]' );
		if ( ! valueEl ) {
			return;
		}
		var target = parseFloat( el.getAttribute( 'data-wpgjb-counter-target' ) ) || 0;
		var duration = parseInt( el.getAttribute( 'data-wpgjb-counter-duration' ), 10 ) || 2000;
		var start = null;

		function step( timestamp ) {
			if ( ! start ) {
				start = timestamp;
			}
			var progress = Math.min( ( timestamp - start ) / duration, 1 );
			var current = Math.round( target * progress );
			valueEl.textContent = current;
			if ( progress < 1 ) {
				window.requestAnimationFrame( step );
			} else {
				valueEl.textContent = target;
			}
		}

		window.requestAnimationFrame( step );
	}

	function initCounters() {
		var els = document.querySelectorAll( '[data-wpgjb-counter]' );
		if ( ! els.length ) {
			return;
		}
		if ( ! window.IntersectionObserver ) {
			els.forEach( animateCounter );
			return;
		}
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						animateCounter( entry.target );
						observer.unobserve( entry.target );
					}
				} );
			},
			{ threshold: 0.4 }
		);
		els.forEach( function ( el ) {
			observer.observe( el );
		} );
	}

	function initGalleryLightbox() {
		var overlay = null;

		function openLightbox( src, alt ) {
			if ( ! overlay ) {
				overlay = document.createElement( 'div' );
				overlay.setAttribute( 'data-wpgjb-lightbox', 'true' );
				overlay.style.cssText =
					'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.85);display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
				var img = document.createElement( 'img' );
				img.style.cssText = 'max-width:90vw;max-height:90vh;object-fit:contain;';
				overlay.appendChild( img );
				overlay.addEventListener( 'click', function () {
					overlay.remove();
					overlay = null;
				} );
				document.body.appendChild( overlay );
			}
			var imgEl = overlay.querySelector( 'img' );
			imgEl.src = src;
			imgEl.alt = alt || '';
		}

		window.WPGJB.onDelegate( '[data-wpgjb-gallery-item]', 'click', function ( e, target ) {
			openLightbox( target.getAttribute( 'src' ), target.getAttribute( 'alt' ) );
		} );
	}

	function setSliderIndex( slider, index ) {
		var track = slider.querySelector( '[data-wpgjb-slider-track]' );
		var slides = slider.querySelectorAll( '[data-wpgjb-slide]' );
		var dots = slider.querySelectorAll( '[data-wpgjb-slider-dot]' );
		if ( ! track || ! slides.length ) {
			return;
		}
		var clamped = ( index + slides.length ) % slides.length;
		track.style.transform = 'translateX(-' + clamped * 100 + '%)';
		dots.forEach( function ( dot, i ) {
			dot.style.opacity = i === clamped ? '1' : '0.5';
		} );
		slider.setAttribute( 'data-wpgjb-slider-index', String( clamped ) );
	}

	function initSliders() {
		var sliders = document.querySelectorAll( '[data-wpgjb-slider]' );
		sliders.forEach( function ( slider ) {
			setSliderIndex( slider, parseInt( slider.getAttribute( 'data-wpgjb-slider-index' ), 10 ) || 0 );
		} );

		window.WPGJB.onDelegate( '[data-wpgjb-slider-prev]', 'click', function ( e, target ) {
			var slider = target.closest( '[data-wpgjb-slider]' );
			if ( slider ) {
				setSliderIndex( slider, ( parseInt( slider.getAttribute( 'data-wpgjb-slider-index' ), 10 ) || 0 ) - 1 );
			}
		} );
		window.WPGJB.onDelegate( '[data-wpgjb-slider-next]', 'click', function ( e, target ) {
			var slider = target.closest( '[data-wpgjb-slider]' );
			if ( slider ) {
				setSliderIndex( slider, ( parseInt( slider.getAttribute( 'data-wpgjb-slider-index' ), 10 ) || 0 ) + 1 );
			}
		} );
		window.WPGJB.onDelegate( '[data-wpgjb-slider-dot]', 'click', function ( e, target ) {
			var slider = target.closest( '[data-wpgjb-slider]' );
			if ( slider ) {
				setSliderIndex( slider, parseInt( target.getAttribute( 'data-index' ), 10 ) || 0 );
			}
		} );
	}

	function prefersReducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	function initCharts() {
		var containers = document.querySelectorAll( '.wpgjb-chart-reveal' );
		if ( ! containers.length || prefersReducedMotion() ) {
			return;
		}

		containers.forEach( function ( el ) {
			el.style.opacity = '0';
			el.style.transform = 'translateY(14px)';
			el.querySelectorAll( '.wpgjb-chart-bar' ).forEach( function ( bar ) {
				bar.style.transform = 'scale(0)';
			} );
			var path = el.querySelector( '.wpgjb-chart-line-path' );
			if ( path && path.getTotalLength ) {
				var len = path.getTotalLength();
				path.style.strokeDasharray = String( len );
				path.style.strokeDashoffset = String( len );
			}
		} );

		function reveal( el ) {
			el.style.opacity = '1';
			el.style.transform = 'translateY(0)';
			el.querySelectorAll( '.wpgjb-chart-bar' ).forEach( function ( bar, i ) {
				setTimeout( function () {
					bar.style.transform = 'scale(1)';
				}, i * 70 );
			} );
			var path = el.querySelector( '.wpgjb-chart-line-path' );
			if ( path ) {
				path.style.transition = 'stroke-dashoffset 1s ease';
				path.style.strokeDashoffset = '0';
			}
		}

		if ( ! window.IntersectionObserver ) {
			containers.forEach( reveal );
			return;
		}
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						reveal( entry.target );
						observer.unobserve( entry.target );
					}
				} );
			},
			{ threshold: 0.3 }
		);
		containers.forEach( function ( el ) {
			observer.observe( el );
		} );
	}

	function parseCellSortValue( text ) {
		var n = parseFloat( String( text ).replace( /[^0-9.\-]/g, '' ) );
		return isNaN( n ) ? null : n;
	}

	function sortDataTable( table, colIndex, dir ) {
		var tbody = table.querySelector( '[data-wpgjb-datatable-tbody]' );
		if ( ! tbody ) {
			return;
		}
		var rows = Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) );
		var withKey = rows.map( function ( row ) {
			var cell = row.children[ colIndex ];
			var text = cell ? cell.textContent.trim() : '';
			return { row: row, num: parseCellSortValue( text ), text: text.toLowerCase() };
		} );
		withKey.sort( function ( a, b ) {
			var cmp;
			if ( null !== a.num && null !== b.num ) {
				cmp = a.num - b.num;
			} else {
				cmp = a.text < b.text ? -1 : a.text > b.text ? 1 : 0;
			}
			return 'desc' === dir ? -cmp : cmp;
		} );

		var reduced = prefersReducedMotion();
		if ( ! reduced ) {
			rows.forEach( function ( row ) {
				row.style.transition = 'opacity .15s ease';
				row.style.opacity = '0';
			} );
		}
		setTimeout(
			function () {
				withKey.forEach( function ( item ) {
					tbody.appendChild( item.row );
				} );
				if ( ! reduced ) {
					window.requestAnimationFrame( function () {
						rows.forEach( function ( row ) {
							row.style.opacity = '1';
						} );
					} );
				}
			},
			reduced ? 0 : 150
		);
	}

	function initDataTables() {
		window.WPGJB.onDelegate( '[data-wpgjb-datatable-thead] th', 'click', function ( e, target ) {
			var table = target.closest( '[data-wpgjb-datatable]' );
			var headerRow = target.parentElement;
			if ( ! table || ! headerRow ) {
				return;
			}
			var ths = Array.prototype.slice.call( headerRow.children );
			var colIndex = ths.indexOf( target );
			var dir = 'ascending' === target.getAttribute( 'aria-sort' ) ? 'descending' : 'ascending';
			ths.forEach( function ( th ) {
				th.removeAttribute( 'aria-sort' );
				var indicator = th.querySelector( '[data-wpgjb-sort-indicator]' );
				if ( indicator ) {
					indicator.remove();
				}
			} );
			target.setAttribute( 'aria-sort', dir );
			var indicator = document.createElement( 'span' );
			indicator.setAttribute( 'data-wpgjb-sort-indicator', 'true' );
			indicator.textContent = 'ascending' === dir ? ' ▲' : ' ▼';
			target.appendChild( indicator );
			sortDataTable( table, colIndex, 'ascending' === dir ? 'asc' : 'desc' );
		} );
	}

	var TAB_ACCENT = 'var(--wp--preset--color--contrast, #2271b1)';

	function setActiveTab( tabsEl, index ) {
		tabsEl.querySelectorAll( '[data-wpgjb-tab-nav]' ).forEach( function ( btn ) {
			var isActive = btn.getAttribute( 'data-index' ) === index;
			btn.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			btn.style.borderBottomColor = isActive ? TAB_ACCENT : 'transparent';
			btn.style.fontWeight = isActive ? '600' : '400';
			btn.style.opacity = isActive ? '1' : '.7';
		} );
		tabsEl.querySelectorAll( '[data-wpgjb-tab-panel]' ).forEach( function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-index' ) !== index;
		} );
	}

	function initTabs() {
		window.WPGJB.onDelegate( '[data-wpgjb-tab-nav]', 'click', function ( e, target ) {
			var tabs = target.closest( '[data-wpgjb-tabs]' );
			if ( tabs ) {
				setActiveTab( tabs, target.getAttribute( 'data-index' ) );
			}
		} );
	}

	function setFlipCard( card, flipped ) {
		var inner = card.querySelector( '[data-wpgjb-flipcard-inner]' );
		if ( inner ) {
			inner.style.transform = flipped ? 'rotateY(180deg)' : '';
		}
	}

	function initFlipCards() {
		document.querySelectorAll( '[data-wpgjb-flipcard]' ).forEach( function ( card ) {
			card.addEventListener( 'mouseenter', function () {
				setFlipCard( card, true );
			} );
			card.addEventListener( 'mouseleave', function () {
				setFlipCard( card, false );
			} );
		} );
		window.WPGJB.onDelegate( '[data-wpgjb-flipcard]', 'click', function ( e, target ) {
			var inner = target.querySelector( '[data-wpgjb-flipcard-inner]' );
			if ( inner ) {
				setFlipCard( target, 'rotateY(180deg)' !== inner.style.transform );
			}
		} );
	}

	function initHotspots() {
		window.WPGJB.onDelegate( '[data-wpgjb-hotspot-dot]', 'click', function ( e, target ) {
			var tip = target.querySelector( '[data-wpgjb-hotspot-tip]' );
			if ( ! tip ) {
				return;
			}
			var wasOpen = 'block' === tip.style.display;
			document.querySelectorAll( '[data-wpgjb-hotspot-tip]' ).forEach( function ( el ) {
				el.style.display = 'none';
			} );
			document.querySelectorAll( '[data-wpgjb-hotspot-dot]' ).forEach( function ( el ) {
				el.setAttribute( 'aria-expanded', 'false' );
			} );
			if ( ! wasOpen ) {
				tip.style.display = 'block';
				target.setAttribute( 'aria-expanded', 'true' );
			}
		} );
	}

	function initCodeCopyButtons() {
		window.WPGJB.onDelegate( '[data-wpgjb-codeblock-copy]', 'click', function ( e, target ) {
			var block = target.closest( '[data-wpgjb-codeblock]' );
			var codeEl = block && block.querySelector( '[data-wpgjb-codeblock-code]' );
			if ( ! codeEl || ! navigator.clipboard ) {
				return;
			}
			navigator.clipboard
				.writeText( codeEl.textContent )
				.then( function () {
					var original = target.textContent;
					var copiedLabel = target.getAttribute( 'data-wpgjb-codeblock-copied-label' );
					if ( copiedLabel ) {
						target.textContent = copiedLabel;
					}
					setTimeout( function () {
						target.textContent = original;
					}, 1500 );
				} )
				.catch( function () {} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initCountdowns();
		initCounters();
		initGalleryLightbox();
		initSliders();
		initCharts();
		initDataTables();
		initTabs();
		initFlipCards();
		initHotspots();
		initCodeCopyButtons();
	} );
} )();
