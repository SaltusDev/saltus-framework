/**
 * Relationship picker.
 *
 * One control serves all four cardinalities: `has_one` and `belongs_to` are the
 * same picker with a cap of one. The server enforces the cap regardless, so the
 * cap here is a convenience, not the guard.
 *
 * Selection state lives in the hidden inputs, in document order. Reordering
 * moves list items and the inputs move with them, so the submitted order is
 * whatever the list shows without a separate order field to keep in step.
 *
 * Search goes to WordPress core's own REST collection for the target post type,
 * so no Saltus route is needed and core's capability handling applies.
 */
( function () {
	'use strict';

	var SEARCH_DEBOUNCE_MS = 250;
	var MIN_QUERY_LENGTH = 2;

	var settings = window.saltusRelationships || {};
	var restRoot = ( settings.restRoot || '/wp-json/' ).replace( /\/?$/, '/' );
	var nonce = settings.nonce || '';
	var strings = settings.strings || {};

	function text( key, fallback ) {
		return typeof strings[ key ] === 'string' ? strings[ key ] : fallback;
	}

	/** Post type slug to its REST collection path. */
	function restBaseFor( postType ) {
		var overrides = settings.restBases || {};
		if ( typeof overrides[ postType ] === 'string' ) {
			return overrides[ postType ];
		}
		// Core's default for a custom post type is the slug itself. Types that
		// set their own rest_base are resolved server-side into restBases.
		return postType;
	}

	function Picker( root ) {
		this.root = root;
		this.relationship = root.dataset.saltusRelationship || '';
		this.target = root.dataset.target || '';
		this.multiple = root.dataset.multiple === '1';
		this.postId = parseInt( root.dataset.post || '0', 10 );

		this.search = root.querySelector( '.saltus-relationship-search' );
		this.results = root.querySelector( '.saltus-relationship-results' );
		this.selected = root.querySelector( '.saltus-relationship-selected' );
		this.status = root.querySelector( '.saltus-relationship-status' );

		this.timer = null;
		this.controller = null;
		this.activeIndex = -1;

		this.bind();
		this.syncCapState();
	}

	Picker.prototype.bind = function () {
		var self = this;

		if ( this.search ) {
			this.search.addEventListener( 'input', function () {
				self.scheduleSearch();
			} );

			this.search.addEventListener( 'keydown', function ( event ) {
				self.onSearchKeydown( event );
			} );

			// Closing on blur would fire before a click on a result lands, so the
			// close is deferred enough for the click to register first.
			this.search.addEventListener( 'blur', function () {
				window.setTimeout( function () {
					if ( ! self.root.contains( document.activeElement ) ) {
						self.closeResults();
					}
				}, 120 );
			} );
		}

		if ( this.selected ) {
			this.selected.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '.saltus-relationship-remove' );
				if ( button ) {
					event.preventDefault();
					self.removeItem( button.closest( '.saltus-relationship-item' ) );
				}
			} );

			this.selected.addEventListener( 'keydown', function ( event ) {
				self.onSelectedKeydown( event );
			} );
		}

		if ( this.results ) {
			this.results.addEventListener( 'click', function ( event ) {
				var option = event.target.closest( '[data-id]' );
				if ( option ) {
					event.preventDefault();
					self.addItem(
						parseInt( option.dataset.id, 10 ),
						option.dataset.title || ''
					);
				}
			} );
		}
	};

	Picker.prototype.scheduleSearch = function () {
		var self = this;
		window.clearTimeout( this.timer );
		this.timer = window.setTimeout( function () {
			self.runSearch();
		}, SEARCH_DEBOUNCE_MS );
	};

	Picker.prototype.runSearch = function () {
		var query = this.search ? this.search.value.trim() : '';

		if ( query.length < MIN_QUERY_LENGTH ) {
			this.closeResults();
			return;
		}

		// A superseded request must not paint over a newer one's results.
		if ( this.controller ) {
			this.controller.abort();
		}
		this.controller = new AbortController();

		var url =
			restRoot +
			'wp/v2/' +
			encodeURIComponent( restBaseFor( this.target ) ) +
			'?per_page=20&_fields=id,title&search=' +
			encodeURIComponent( query );

		var self = this;
		var headers = {};
		if ( nonce ) {
			headers[ 'X-WP-Nonce' ] = nonce;
		}

		this.announce( text( 'searching', 'Searching…' ) );

		window
			.fetch( url, {
				headers: headers,
				credentials: 'same-origin',
				signal: this.controller.signal,
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'search failed' );
				}
				return response.json();
			} )
			.then( function ( items ) {
				self.renderResults( Array.isArray( items ) ? items : [] );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}
				self.closeResults();
				self.announce( text( 'searchFailed', 'Search failed.' ) );
			} );
	};

	Picker.prototype.renderResults = function ( items ) {
		if ( ! this.results ) {
			return;
		}

		var chosen = this.selectedIds();
		var available = items.filter( function ( item ) {
			return chosen.indexOf( parseInt( item.id, 10 ) ) === -1;
		} );

		this.results.innerHTML = '';
		this.activeIndex = -1;

		if ( available.length === 0 ) {
			this.closeResults();
			this.announce( text( 'noResults', 'No matches found.' ) );
			return;
		}

		var self = this;
		available.forEach( function ( item ) {
			var title =
				item.title && typeof item.title.rendered === 'string'
					? item.title.rendered
					: '#' + item.id;

			var option = document.createElement( 'li' );
			option.className = 'saltus-relationship-result';
			option.setAttribute( 'role', 'option' );
			option.tabIndex = -1;
			option.dataset.id = String( item.id );
			// textContent, not innerHTML: core returns rendered titles which may
			// carry entities, and this must not become an injection point.
			option.textContent = self.decode( title );
			option.dataset.title = option.textContent;
			self.results.appendChild( option );
		} );

		this.results.hidden = false;
		this.announce(
			available.length === 1
				? text( 'oneResult', '1 match found.' )
				: available.length + ' ' + text( 'manyResults', 'matches found.' )
		);
	};

	/** Resolve entities in a core-rendered title without parsing it as markup. */
	Picker.prototype.decode = function ( value ) {
		var area = document.createElement( 'textarea' );
		area.innerHTML = value;
		return area.value;
	};

	Picker.prototype.onSearchKeydown = function ( event ) {
		if ( ! this.results || this.results.hidden ) {
			return;
		}

		var options = this.resultOptions();
		if ( options.length === 0 ) {
			return;
		}

		if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			this.moveActive( 1, options );
		} else if ( event.key === 'ArrowUp' ) {
			event.preventDefault();
			this.moveActive( -1, options );
		} else if ( event.key === 'Enter' ) {
			if ( this.activeIndex >= 0 && options[ this.activeIndex ] ) {
				event.preventDefault();
				var option = options[ this.activeIndex ];
				this.addItem(
					parseInt( option.dataset.id, 10 ),
					option.dataset.title || ''
				);
			}
		} else if ( event.key === 'Escape' ) {
			this.closeResults();
		}
	};

	Picker.prototype.moveActive = function ( delta, options ) {
		options.forEach( function ( option ) {
			option.classList.remove( 'is-active' );
			option.setAttribute( 'aria-selected', 'false' );
		} );

		this.activeIndex += delta;
		if ( this.activeIndex < 0 ) {
			this.activeIndex = options.length - 1;
		} else if ( this.activeIndex >= options.length ) {
			this.activeIndex = 0;
		}

		var active = options[ this.activeIndex ];
		active.classList.add( 'is-active' );
		active.setAttribute( 'aria-selected', 'true' );
		if ( typeof active.scrollIntoView === 'function' ) {
			active.scrollIntoView( { block: 'nearest' } );
		}
	};

	/** Reorder with the keyboard, so ordering is not mouse-only. */
	Picker.prototype.onSelectedKeydown = function ( event ) {
		if ( ! event.altKey ) {
			return;
		}

		var item = event.target.closest( '.saltus-relationship-item' );
		if ( ! item ) {
			return;
		}

		if ( event.key === 'ArrowUp' && item.previousElementSibling ) {
			event.preventDefault();
			item.parentNode.insertBefore( item, item.previousElementSibling );
			event.target.focus();
			this.announce( text( 'movedUp', 'Moved up.' ) );
		} else if ( event.key === 'ArrowDown' && item.nextElementSibling ) {
			event.preventDefault();
			item.parentNode.insertBefore( item.nextElementSibling, item );
			event.target.focus();
			this.announce( text( 'movedDown', 'Moved down.' ) );
		}
	};

	Picker.prototype.addItem = function ( id, title ) {
		if ( ! id || id <= 0 || ! this.selected ) {
			return;
		}

		if ( this.selectedIds().indexOf( id ) !== -1 ) {
			return;
		}

		// A single-value relationship replaces rather than appends, so the control
		// cannot present a state the server would reject.
		if ( ! this.multiple ) {
			this.selected.innerHTML = '';
		}

		var item = document.createElement( 'li' );
		item.className = 'saltus-relationship-item';
		item.dataset.id = String( id );

		var label = document.createElement( 'span' );
		label.className = 'saltus-relationship-item-title';
		label.textContent = title || '#' + id;
		item.appendChild( label );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link saltus-relationship-remove';
		remove.textContent = text( 'remove', 'Remove' );
		remove.setAttribute(
			'aria-label',
			text( 'remove', 'Remove' ) + ' ' + label.textContent
		);
		item.appendChild( remove );

		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = 'saltus_relationships[' + this.relationship + '][]';
		input.value = String( id );
		item.appendChild( input );

		this.selected.appendChild( item );

		if ( this.search ) {
			this.search.value = '';
			this.search.focus();
		}
		this.closeResults();
		this.syncCapState();
		this.announce( text( 'added', 'Added.' ) + ' ' + label.textContent );
	};

	Picker.prototype.removeItem = function ( item ) {
		if ( ! item ) {
			return;
		}

		var title = item.querySelector( '.saltus-relationship-item-title' );
		var label = title ? title.textContent : '';

		// Focus would otherwise land on <body> after the node goes.
		var next =
			item.nextElementSibling || item.previousElementSibling || this.search;

		item.parentNode.removeChild( item );

		if ( next ) {
			var button = next.querySelector
				? next.querySelector( '.saltus-relationship-remove' )
				: null;
			( button || next ).focus();
		}

		this.syncCapState();
		this.announce( text( 'removed', 'Removed.' ) + ' ' + label );
	};

	/** Disable search once a single-value relationship is filled. */
	Picker.prototype.syncCapState = function () {
		if ( this.multiple || ! this.search ) {
			return;
		}

		var full = this.selectedIds().length >= 1;
		this.search.disabled = full;
		this.root.classList.toggle( 'is-full', full );
	};

	Picker.prototype.selectedIds = function () {
		if ( ! this.selected ) {
			return [];
		}

		return Array.prototype.map.call(
			this.selected.querySelectorAll( '.saltus-relationship-item' ),
			function ( item ) {
				return parseInt( item.dataset.id, 10 );
			}
		);
	};

	Picker.prototype.resultOptions = function () {
		if ( ! this.results ) {
			return [];
		}

		return Array.prototype.slice.call(
			this.results.querySelectorAll( '[data-id]' )
		);
	};

	Picker.prototype.closeResults = function () {
		if ( this.results ) {
			this.results.hidden = true;
			this.results.innerHTML = '';
		}
		this.activeIndex = -1;
	};

	Picker.prototype.announce = function ( message ) {
		if ( this.status ) {
			this.status.textContent = message;
		}
	};

	function init() {
		var controls = document.querySelectorAll(
			'.saltus-relationship-control'
		);
		Array.prototype.forEach.call( controls, function ( control ) {
			if ( ! control.dataset.saltusPickerReady ) {
				control.dataset.saltusPickerReady = '1';
				new Picker( control );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Exported for the test suite; harmless in the browser.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { Picker: Picker, init: init };
	}
} )();
