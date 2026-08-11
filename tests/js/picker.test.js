/**
 * Tests for assets/Feature/Relationships/picker.js.
 *
 * The picker manipulates DOM directly, so these run against a small hand-built
 * DOM rather than jsdom — the framework has no jsdom dependency and the surface
 * the picker touches is narrow enough to model honestly: element creation,
 * class and dataset access, `closest`, `querySelector(All)`, and events.
 *
 * What is worth testing here is the state that reaches the server: the hidden
 * inputs and their order. Cardinality, id validity, and capability are enforced
 * server-side and covered by RelationshipMetaboxTest.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const vm = require( 'node:vm' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const PICKER = fs.readFileSync(
	path.join( __dirname, '../../assets/Feature/Relationships/picker.js' ),
	'utf8'
);

/** Minimal element implementing only what the picker uses. */
class El {
	constructor( tag ) {
		this.tagName = String( tag ).toUpperCase();
		this.children = [];
		this.parentNode = null;
		this.dataset = {};
		this.attributes = {};
		this.listeners = {};
		this.textContent = '';
		this.value = '';
		this.type = '';
		this.name = '';
		this.hidden = false;
		this.disabled = false;
		this.tabIndex = 0;
		this._className = '';
		this.classList = {
			add: ( c ) => this._classes().add( c ) && this._sync(),
			remove: ( c ) => this._classes().delete( c ) && this._sync(),
			contains: ( c ) => this._classes().has( c ),
			toggle: ( c, on ) => {
				const set = this._classes();
				if ( on === undefined ? set.has( c ) : ! on ) {
					set.delete( c );
				} else {
					set.add( c );
				}
				this._sync( set );
			},
		};
		this._classSet = new Set();
	}

	_classes() {
		return this._classSet;
	}

	_sync( set ) {
		this._className = Array.from( set || this._classSet ).join( ' ' );
	}

	get className() {
		return this._className;
	}

	set className( value ) {
		this._className = String( value );
		this._classSet = new Set(
			this._className.split( /\s+/ ).filter( Boolean )
		);
	}

	get innerHTML() {
		return this.children.map( ( c ) => c.textContent ).join( '' );
	}

	set innerHTML( value ) {
		this.children.forEach( ( c ) => {
			c.parentNode = null;
		} );
		this.children = [];

		// A textarea is how the picker resolves HTML entities in a core-rendered
		// title. Model that one behavior — assigning innerHTML sets .value with
		// entities decoded — without becoming a parser. Any other element
		// assigning a non-empty string would be building DOM from a string, which
		// the picker must never do.
		if ( this.tagName === 'TEXTAREA' ) {
			this.value = String( value )
				.replace( /&lt;/g, '<' )
				.replace( /&gt;/g, '>' )
				.replace( /&quot;/g, '"' )
				.replace( /&#0?39;/g, "'" )
				.replace( /&amp;/g, '&' );
			return;
		}

		assert.strictEqual(
			value,
			'',
			'innerHTML on a non-textarea is only for clearing'
		);
	}

	appendChild( child ) {
		child.parentNode = this;
		this.children.push( child );
		return child;
	}

	insertBefore( node, ref ) {
		const from = this.children.indexOf( node );
		if ( from !== -1 ) {
			this.children.splice( from, 1 );
		}
		const at = this.children.indexOf( ref );
		this.children.splice( at === -1 ? this.children.length : at, 0, node );
		node.parentNode = this;
		return node;
	}

	removeChild( child ) {
		const at = this.children.indexOf( child );
		if ( at !== -1 ) {
			this.children.splice( at, 1 );
			child.parentNode = null;
		}
		return child;
	}

	setAttribute( name, value ) {
		this.attributes[ name ] = String( value );
	}

	getAttribute( name ) {
		return Object.prototype.hasOwnProperty.call( this.attributes, name )
			? this.attributes[ name ]
			: null;
	}

	get previousElementSibling() {
		if ( ! this.parentNode ) {
			return null;
		}
		const at = this.parentNode.children.indexOf( this );
		return at > 0 ? this.parentNode.children[ at - 1 ] : null;
	}

	get nextElementSibling() {
		if ( ! this.parentNode ) {
			return null;
		}
		const at = this.parentNode.children.indexOf( this );
		return at !== -1 && at < this.parentNode.children.length - 1
			? this.parentNode.children[ at + 1 ]
			: null;
	}

	/** Depth-first descendants, self excluded. */
	_descendants() {
		const out = [];
		this.children.forEach( ( child ) => {
			out.push( child );
			out.push( ...child._descendants() );
		} );
		return out;
	}

	_matches( selector ) {
		if ( selector.startsWith( '.' ) ) {
			return this.classList.contains( selector.slice( 1 ) );
		}
		if ( selector === '[data-id]' ) {
			return this.dataset.id !== undefined;
		}
		return this.tagName === selector.toUpperCase();
	}

	querySelector( selector ) {
		return this._descendants().find( ( e ) => e._matches( selector ) ) || null;
	}

	querySelectorAll( selector ) {
		return this._descendants().filter( ( e ) => e._matches( selector ) );
	}

	closest( selector ) {
		let node = this;
		while ( node ) {
			if ( node._matches && node._matches( selector ) ) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	contains( node ) {
		return node === this || this._descendants().indexOf( node ) !== -1;
	}

	addEventListener( type, handler ) {
		( this.listeners[ type ] = this.listeners[ type ] || [] ).push( handler );
	}

	/**
	 * Fire listeners bound on this element.
	 *
	 * `target` defaults to this element but an explicit one in `event` wins, so a
	 * delegated handler can be given the descendant that was really clicked.
	 */
	dispatch( type, event ) {
		const payload = Object.assign(
			{ target: this, preventDefault() {} },
			event || {}
		);
		( this.listeners[ type ] || [] ).forEach( ( handler ) =>
			handler( payload )
		);
	}

	focus() {
		this.focused = true;
	}

	scrollIntoView() {}
}

/**
 * Build a picker over a synthetic control and return handles to it.
 *
 * @param {object} options              Scenario options.
 * @param {boolean} options.multiple    Whether the relationship takes many.
 * @param {Array} options.selected      Pre-selected [id, title] pairs.
 * @param {Function|undefined} options.fetch Stub fetch.
 * @return {object} The picker instance and its parts.
 */
function build( options = {} ) {
	const multiple = options.multiple !== false;

	const control = new El( 'div' );
	control.className = 'saltus-relationship-control';
	control.dataset.saltusRelationship = 'actors';
	control.dataset.target = 'person';
	control.dataset.multiple = multiple ? '1' : '0';
	control.dataset.post = '10';

	const search = new El( 'input' );
	search.className = 'saltus-relationship-search';
	control.appendChild( search );

	const results = new El( 'ul' );
	results.className = 'saltus-relationship-results';
	results.hidden = true;
	control.appendChild( results );

	const selected = new El( 'ul' );
	selected.className = 'saltus-relationship-selected';
	control.appendChild( selected );

	const status = new El( 'p' );
	status.className = 'saltus-relationship-status';
	control.appendChild( status );

	( options.selected || [] ).forEach( ( [ id, title ] ) => {
		const item = new El( 'li' );
		item.className = 'saltus-relationship-item';
		item.dataset.id = String( id );

		const label = new El( 'span' );
		label.className = 'saltus-relationship-item-title';
		label.textContent = title;
		item.appendChild( label );

		const remove = new El( 'button' );
		remove.className = 'button-link saltus-relationship-remove';
		item.appendChild( remove );

		const input = new El( 'input' );
		input.type = 'hidden';
		input.name = 'saltus_relationships[actors][]';
		input.value = String( id );
		item.appendChild( input );

		selected.appendChild( item );
	} );

	const document_ = {
		readyState: 'complete',
		activeElement: null,
		createElement: ( tag ) => new El( tag ),
		querySelectorAll: ( selector ) =>
			selector === '.saltus-relationship-control' ? [ control ] : [],
		addEventListener() {},
	};

	const sandbox = {
		window: {
			saltusRelationships: {
				restRoot: 'https://example.test/wp-json/',
				nonce: 'test-nonce',
				strings: {},
			},
			fetch: options.fetch || ( () => Promise.reject( new Error( 'no fetch' ) ) ),
			setTimeout: ( fn ) => fn(),
			clearTimeout: () => {},
		},
		document: document_,
		AbortController: class {
			constructor() {
				this.signal = {};
			}
			abort() {
				this.aborted = true;
			}
		},
		module: { exports: {} },
		console,
	};
	sandbox.globalThis = sandbox;
	sandbox.window.document = document_;

	vm.createContext( sandbox );
	vm.runInContext( PICKER, sandbox );

	return { control, search, results, selected, status, sandbox };
}

/** Ids in the order the form would submit them. */
function submittedIds( selected ) {
	return selected
		.querySelectorAll( '.saltus-relationship-item' )
		.map( ( item ) => item.querySelector( 'input' ).value );
}

test( 'pre-selected items are submittable in stored order', () => {
	const { selected } = build( {
		selected: [
			[ 20, 'Ripley' ],
			[ 21, 'Hicks' ],
		],
	} );

	assert.deepStrictEqual( submittedIds( selected ), [ '20', '21' ] );
} );

test( 'removing an item drops its hidden input', () => {
	const { selected } = build( {
		selected: [
			[ 20, 'Ripley' ],
			[ 21, 'Hicks' ],
		],
	} );

	const first = selected.querySelectorAll( '.saltus-relationship-item' )[ 0 ];

	// The listener is delegated on the list, so fire there with the button as the
	// event target, which is what a real click produces.
	selected.dispatch( 'click', {
		target: first.querySelector( '.saltus-relationship-remove' ),
	} );

	assert.deepStrictEqual( submittedIds( selected ), [ '21' ] );
} );

test( 'alt+arrow reorders the submitted set', () => {
	const { selected } = build( {
		selected: [
			[ 20, 'Ripley' ],
			[ 21, 'Hicks' ],
		],
	} );

	const second = selected.querySelectorAll( '.saltus-relationship-item' )[ 1 ];
	selected.dispatch( 'keydown', {
		target: second.querySelector( '.saltus-relationship-remove' ),
		altKey: true,
		key: 'ArrowUp',
		preventDefault() {},
	} );

	assert.deepStrictEqual(
		submittedIds( selected ),
		[ '21', '20' ],
		'Keyboard reorder must change what the form submits, not just the view.'
	);
} );

test( 'alt+arrow at the end of the list is a no-op', () => {
	const { selected } = build( {
		selected: [
			[ 20, 'Ripley' ],
			[ 21, 'Hicks' ],
		],
	} );

	const first = selected.querySelectorAll( '.saltus-relationship-item' )[ 0 ];
	selected.dispatch( 'keydown', {
		target: first.querySelector( '.saltus-relationship-remove' ),
		altKey: true,
		key: 'ArrowUp',
		preventDefault() {},
	} );

	assert.deepStrictEqual( submittedIds( selected ), [ '20', '21' ] );
} );

test( 'a single-value picker disables search once filled', () => {
	const { search } = build( {
		multiple: false,
		selected: [ [ 20, 'Cameron' ] ],
	} );

	assert.strictEqual(
		search.disabled,
		true,
		'A has_one already holding a value must not invite a second.'
	);
} );

test( 'a single-value picker leaves search open while empty', () => {
	const { search } = build( { multiple: false, selected: [] } );

	assert.strictEqual( search.disabled, false );
} );

test( 'a multi-value picker never disables search', () => {
	const { search } = build( {
		multiple: true,
		selected: [
			[ 20, 'Ripley' ],
			[ 21, 'Hicks' ],
		],
	} );

	assert.strictEqual( search.disabled, false );
} );

test( 'search below the minimum length issues no request', async () => {
	let called = false;
	const { search } = build( {
		fetch: () => {
			called = true;
			return Promise.resolve( { ok: true, json: () => Promise.resolve( [] ) } );
		},
	} );

	search.value = 'a';
	search.dispatch( 'input', { target: search } );

	await new Promise( ( resolve ) => setImmediate( resolve ) );
	assert.strictEqual( called, false, 'One character must not hit the API.' );
} );

test( 'search results exclude already-selected ids', async () => {
	const { search, results } = build( {
		selected: [ [ 20, 'Ripley' ] ],
		fetch: () =>
			Promise.resolve( {
				ok: true,
				json: () =>
					Promise.resolve( [
						{ id: 20, title: { rendered: 'Ripley' } },
						{ id: 21, title: { rendered: 'Hicks' } },
					] ),
			} ),
	} );

	search.value = 'ri';
	search.dispatch( 'input', { target: search } );

	await new Promise( ( resolve ) => setImmediate( resolve ) );

	const offered = results
		.querySelectorAll( '[data-id]' )
		.map( ( o ) => o.dataset.id );
	assert.deepStrictEqual( offered, [ '21' ], 'A selected id must not be offered again.' );
} );

test( 'a rendered title is set as text, never parsed as markup', async () => {
	const { search, results } = build( {
		fetch: () =>
			Promise.resolve( {
				ok: true,
				json: () =>
					Promise.resolve( [
						{ id: 21, title: { rendered: '<img src=x onerror=alert(1)>' } },
					] ),
			} ),
	} );

	search.value = 'img';
	search.dispatch( 'input', { target: search } );

	await new Promise( ( resolve ) => setImmediate( resolve ) );

	const option = results.querySelectorAll( '[data-id]' )[ 0 ];
	assert.strictEqual( option.children.length, 0, 'No child elements may be built from a title.' );
	assert.match( option.textContent, /img src=x/ );
} );

test( 'a failed search closes the result list rather than leaving it stale', async () => {
	const { search, results } = build( {
		fetch: () => Promise.resolve( { ok: false, status: 500 } ),
	} );

	search.value = 'ripley';
	search.dispatch( 'input', { target: search } );

	await new Promise( ( resolve ) => setImmediate( resolve ) );

	assert.strictEqual( results.hidden, true );
} );
