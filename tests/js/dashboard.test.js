/**
 * Tests for assets/Feature/Observability/dashboard.js.
 *
 * The dashboard is a wp.element component with no build step, so it is exercised
 * here against a hand-built hook runtime rather than through PHPUnit. The
 * runtime is live, not a snapshot: a setState from a resolved fetch re-renders,
 * and ctx.tree()/find()/text() read the newest render every call.
 *
 * fetch is deferred. Nothing resolves until a test calls respond() or fail() on
 * the recorded call, which is what makes the loading state observable at all.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const vm = require( 'node:vm' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const DASHBOARD = fs.readFileSync(
	path.join( __dirname, '../../assets/Feature/Observability/dashboard.js' ),
	'utf8'
);

/**
 * Substitute wp.i18n.sprintf's positional and sequential specifiers.
 *
 * @param {string} format Format string.
 * @param {...unknown} args Substitutions.
 * @return {string} Formatted string.
 */
function sprintf( format, ...args ) {
	let sequential = 0;

	return String( format )
		.replace( /%(\d+)\$[sd]/g, ( match, position ) => String( args[ Number( position ) - 1 ] ) )
		.replace( /%[sd]/g, () => String( args[ sequential++ ] ) );
}

/**
 * A minimal wp.element: function components, keyed hook slots, and effects.
 *
 * Hook state is keyed by the component's position in the tree so it survives
 * re-renders. Children are indexed before nulls are dropped, so a conditional
 * sibling appearing or disappearing does not shift a later component's slots.
 *
 * @return {object} The wp.element surface plus a tree() accessor.
 */
function createRuntime() {
	const store = new Map();
	const effects = [];

	let root = null;
	let tree = null;
	let cursor = '';
	let hookIndex = 0;
	let rendering = false;
	let flushing = false;

	function createElement( type, props, ...children ) {
		const { key = null, ...rest } = props || {};

		return { type, props: rest, key, children: children.flat( Infinity ) };
	}

	function slots( key ) {
		if ( ! store.has( key ) ) {
			store.set( key, [] );
		}

		return store.get( key );
	}

	function renderNode( node, at ) {
		if ( node === null || node === undefined || node === false || node === true ) {
			return null;
		}

		if ( typeof node !== 'object' ) {
			return node;
		}

		if ( typeof node.type === 'function' ) {
			const parentCursor = cursor;
			const parentHookIndex = hookIndex;

			cursor = at + '/' + ( node.type.name || 'anon' ) + ( node.key === null ? '' : '#' + node.key );
			hookIndex = 0;

			const produced = node.type( { ...node.props, children: node.children } );
			const rendered = renderNode( produced, cursor );

			cursor = parentCursor;
			hookIndex = parentHookIndex;

			return rendered;
		}

		return {
			type: node.type,
			props: node.props,
			key: node.key,
			children: node.children
				.map( ( child, index ) => renderNode( child, at + '/' + node.type + ':' + index ) )
				.filter( child => child !== null ),
		};
	}

	function scheduleRender() {
		if ( rendering || root === null ) {
			return;
		}

		pass();
	}

	function pass() {
		rendering = true;
		cursor = '';
		hookIndex = 0;
		tree = renderNode( root, '' );
		rendering = false;

		flushEffects();
	}

	function flushEffects() {
		if ( flushing ) {
			return;
		}

		flushing = true;

		while ( effects.length > 0 ) {
			effects.shift()();
		}

		flushing = false;
	}

	function useState( initial ) {
		const hooks = slots( cursor );
		const index = hookIndex++;

		if ( hooks.length <= index ) {
			hooks[ index ] = { value: typeof initial === 'function' ? initial() : initial };
		}

		const hook = hooks[ index ];

		return [
			hook.value,
			next => {
				const value = typeof next === 'function' ? next( hook.value ) : next;

				if ( value === hook.value ) {
					return;
				}

				hook.value = value;
				scheduleRender();
			},
		];
	}

	function sameDeps( previous, next ) {
		if ( previous === undefined || next === undefined ) {
			return false;
		}

		return previous.length === next.length && previous.every( ( dep, index ) => dep === next[ index ] );
	}

	function useEffect( effect, deps ) {
		const hooks = slots( cursor );
		const index = hookIndex++;
		const hook = hooks[ index ] || ( hooks[ index ] = {} );

		if ( sameDeps( hook.deps, deps ) ) {
			return;
		}

		hook.deps = deps;

		effects.push( () => {
			if ( typeof hook.cleanup === 'function' ) {
				hook.cleanup();
			}

			hook.cleanup = effect();
		} );
	}

	function useRef( initial ) {
		const hooks = slots( cursor );
		const index = hookIndex++;

		if ( hooks.length <= index ) {
			hooks[ index ] = { current: initial };
		}

		return hooks[ index ];
	}

	function useMemo( factory, deps ) {
		const hooks = slots( cursor );
		const index = hookIndex++;
		const hook = hooks[ index ] || ( hooks[ index ] = {} );

		if ( ! sameDeps( hook.deps, deps ) ) {
			hook.deps = deps;
			hook.value = factory();
		}

		return hook.value;
	}

	return {
		element: {
			createElement,
			useState,
			useEffect,
			useRef,
			useMemo,
			useCallback: ( fn, deps ) => useMemo( () => fn, deps ),
			render( node ) {
				root = node;
				pass();
			},
		},
		tree: () => tree,
	};
}

/**
 * A metrics response with every field the dashboard reads.
 *
 * An override set to undefined deletes the key, which is how an absent numeric
 * field is expressed.
 *
 * @param {object} overrides Metrics fields to replace or delete.
 * @param {Array<string>} abilities Ability names for the filter.
 * @return {object} A wp_send_json_success payload.
 */
function metricsPayload( overrides = {}, abilities = [ 'create_post' ] ) {
	const metrics = {
		total_calls: 1234,
		error_rate: 0.0525,
		avg_latency_ms: 42.37,
		daily_calls: {
			'2026-08-12': 100,
			'2026-08-11': 300,
			'2026-08-13': 50,
		},
		per_ability: [
			{ ability: 'create_post', call_count: 900, error_count: 12, avg_duration_ms: 40.1, p95_duration_ms: 91.6 },
		],
		per_client: [
			{ client_identifier: 'claude', call_count: 334, error_count: 3, avg_duration_ms: 44.9, p95_duration_ms: 88.2 },
		],
		sampling: { is_sampled: false, sample_rate: 1.0, sample_rates: [ { rate: 1.0, call_count: 1234 } ], estimated_total_calls: 1234.0 },
		...overrides,
	};

	Object.keys( overrides ).forEach( key => {
		if ( overrides[ key ] === undefined ) {
			delete metrics[ key ];
		}
	} );

	return { success: true, data: { metrics, abilities } };
}

/** Drain the microtask queue the component's awaits are sitting on. */
function flush() {
	return new Promise( resolve => setImmediate( resolve ) );
}

/**
 * Mount the dashboard against a synthetic admin page.
 *
 * @param {object} options Scenario options.
 * @param {Function} options.fetchImpl Replacement fetch, when the default
 *                                     deferred recorder is not wanted.
 * @return {object} Handles onto the live render.
 */
function mount( options = {} ) {
	const runtime = createRuntime();
	const calls = [];
	const container = { id: 'saltus-metrics-dashboard' };

	const fetchImpl = options.fetchImpl || ( ( url, init ) => new Promise( ( resolve, reject ) => {
		calls.push( {
			url,
			signal: init && init.signal,
			respond( payload ) {
				resolve( { json: () => Promise.resolve( payload ) } );
			},
			fail( error ) {
				reject( error || new Error( 'network down' ) );
			},
		} );
	} ) );

	const sandbox = {
		wp: {
			element: runtime.element,
			i18n: {
				__: text => text,
				_n: ( single, plural, count ) => ( count === 1 ? single : plural ),
				sprintf,
			},
		},
		window: {
			saltusMetrics: { nonce: 'nonce-value', ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php' },
		},
		document: {
			getElementById: id => ( id === 'saltus-metrics-dashboard' ? container : null ),
		},
		fetch: fetchImpl,
		URLSearchParams,
		AbortController,
		console,
		setTimeout,
	};

	vm.runInNewContext( DASHBOARD, sandbox );

	const nodes = () => {
		const found = [];
		const walk = node => {
			if ( node === null || typeof node !== 'object' ) {
				return;
			}

			found.push( node );
			node.children.forEach( walk );
		};

		walk( runtime.tree() );

		return found;
	};

	return {
		calls,
		tree: runtime.tree,
		nodes,
		find: predicate => nodes().find( predicate ) || null,
		findAll: predicate => nodes().filter( predicate ),
		text: () => textOf( runtime.tree() ),
		change( id, value ) {
			const node = nodes().find( candidate => candidate.props.id === id );

			assert.ok( node, `no element with id ${ id }` );
			node.props.onChange( { target: { value } } );
		},
		async load( payload ) {
			assert.ok( calls.length > 0, 'no request to respond to' );
			calls[ calls.length - 1 ].respond( payload || metricsPayload() );
			await flush();
		},
	};
}

/**
 * @param {object|string|number|null} node Rendered node.
 * @return {string} All text in the subtree.
 */
function textOf( node ) {
	if ( node === null || node === undefined ) {
		return '';
	}

	if ( typeof node !== 'object' ) {
		return String( node );
	}

	return node.children.map( textOf ).join( ' ' );
}

const byClass = name => node => node.props.className === name;
const bars = ctx => ctx.findAll( byClass( 'saltus-chart-bar' ) );

// The runner's locale decides the group separator, so an expected count is built
// the same way the dashboard builds it rather than written out with a comma.
const grouped = value => value.toLocaleString();

test( 'the chart plots one bar per rolled-up day, scaled to the busiest', async () => {
	const ctx = mount();
	await ctx.load();

	const plotted = bars( ctx );

	assert.strictEqual( plotted.length, 3 );

	// Dates are plotted in calendar order, not response order.
	assert.deepStrictEqual(
		plotted.map( bar => bar.key ),
		[ '2026-08-11', '2026-08-12', '2026-08-13' ]
	);

	const [ busiest, middle, quietest ] = plotted.map( bar => bar.props.height );

	assert.ok( busiest > middle && middle > quietest, `expected descending heights, got ${ busiest }/${ middle }/${ quietest }` );
	assert.ok( quietest > 0, 'a day with calls must have a visible bar' );
} );

test( 'every plotted value is readable as text, not only as bar height', async () => {
	const ctx = mount();
	await ctx.load();

	const table = ctx.find( byClass( 'saltus-chart-data' ) );

	assert.ok( table, 'the chart must ship an accessible data table' );

	const rendered = textOf( table );

	[ '2026-08-11', '300', '2026-08-12', '100', '2026-08-13', '50' ].forEach( value => {
		assert.match( rendered, new RegExp( value ), `${ value } must appear in the data table` );
	} );
} );

test( 'the data table is reachable by keyboard through a native disclosure', async () => {
	const ctx = mount();
	await ctx.load();

	const details = ctx.find( node => node.type === 'details' );

	assert.ok( details, 'the table must sit in a details element' );
	assert.ok(
		details.children.some( child => child.type === 'summary' ),
		'a details without a summary has no focusable control'
	);
} );

test( 'the chart names itself and its summary to assistive technology', async () => {
	const ctx = mount();
	await ctx.load();

	const svg = ctx.find( node => node.type === 'svg' );

	assert.ok( svg, 'the chart must render as inline SVG' );
	assert.strictEqual( svg.props.role, 'img' );

	const labelled = String( svg.props[ 'aria-labelledby' ] ).split( ' ' );
	const ids = ctx.nodes().map( node => node.props.id );

	labelled.forEach( id => {
		assert.ok( ids.includes( id ), `aria-labelledby points at ${ id }, which is not rendered` );
	} );
} );

test( 'the chart states it is loading before any response arrives', () => {
	const ctx = mount();

	assert.strictEqual( bars( ctx ).length, 0 );
	assert.match( ctx.text(), /Loading daily call volume/ );
} );

test( 'an empty window says so instead of leaving a blank chart', async () => {
	const ctx = mount();
	await ctx.load( metricsPayload( { daily_calls: {}, total_calls: 0 } ) );

	assert.strictEqual( bars( ctx ).length, 0 );
	assert.match( ctx.text(), /No calls were recorded in this period/ );
} );

test( 'a failed request leaves the chart in an error state, not an empty one', async () => {
	const ctx = mount();

	ctx.calls[ 0 ].fail();
	await flush();

	assert.strictEqual( bars( ctx ).length, 0 );
	assert.match( ctx.text(), /Daily call volume is unavailable/ );
	assert.ok( ctx.find( node => node.props.role === 'alert' ), 'the page-level error must still be announced' );
} );

test( 'an absent numeric field degrades its own cell and leaves the rest rendered', async () => {
	const ctx = mount();
	await ctx.load( metricsPayload( { avg_latency_ms: undefined } ) );

	// The dashboard must still be on screen: the whole point is that one missing
	// field does not take the render down with it.
	assert.strictEqual( bars( ctx ).length, 3, 'the chart must survive an unrelated missing field' );
	assert.ok( ctx.text().includes( grouped( 1234 ) ), 'the fields that are present must still format' );

	const unavailable = ctx.findAll( byClass( 'saltus-metric-unavailable' ) );

	assert.strictEqual( unavailable.length, 1, 'exactly the missing field degrades' );
	assert.match( textOf( unavailable[ 0 ] ), /Not available/, 'the placeholder must be announced, not a bare dash' );
} );

test( 'a non-numeric field degrades rather than throwing', async () => {
	const ctx = mount();
	await ctx.load( metricsPayload( { total_calls: 'n/a', error_rate: null } ) );

	assert.strictEqual( ctx.findAll( byClass( 'saltus-metric-unavailable' ) ).length, 2 );
	assert.match( ctx.text(), /42.4 ms/, 'the remaining cards must be unaffected' );
} );

test( 'a table row missing a latency degrades that cell only', async () => {
	const ctx = mount();
	await ctx.load( metricsPayload( {
		per_ability: [
			{ ability: 'create_post', call_count: 900, error_count: 12, p95_duration_ms: 91.6 },
		],
	} ) );

	assert.match( ctx.text(), /create_post/, 'the row must still render' );
	assert.match( ctx.text(), /91.6/, 'the row\'s other numbers must still format' );
	assert.strictEqual( ctx.findAll( byClass( 'saltus-metric-unavailable' ) ).length, 1 );
} );

test( 'the estimated total is shown with its own label when sampling is on', async () => {
	const ctx = mount();
	await ctx.load( metricsPayload( {
		total_calls: 250,
		sampling: { is_sampled: true, sample_rate: 0.25, sample_rates: [ { rate: 0.25, call_count: 250 } ], estimated_total_calls: 1000.0 },
	} ) );

	const cards = ctx.findAll( byClass( 'saltus-metric-card' ) ).map( textOf );
	const estimate = cards.find( card => /Estimated Total Calls/.test( card ) );

	assert.ok( estimate, 'the estimate must have its own labelled card' );
	assert.ok( estimate.includes( grouped( 1000 ) ), 'the estimate value must be rendered' );
	assert.match( estimate, /25% sample/, 'the card must say what the estimate came from' );
	assert.ok(
		cards.some( card => /Total Calls/.test( card ) && /250/.test( card ) && /Recorded audit rows/.test( card ) ),
		'the recorded count must stay distinguishable from the estimate'
	);
} );

test( 'an unsampled window shows no estimate card', async () => {
	const ctx = mount();
	await ctx.load();

	// Unsampled, the estimate equals the recorded count; two identical cards read
	// as a bug rather than as a disclosure.
	assert.ok( ! /Estimated Total Calls/.test( ctx.text() ) );
} );

test( 'a mixed-rate window lists the rates it could not reduce to one', async () => {
	const ctx = mount();
	await ctx.load( metricsPayload( {
		sampling: {
			is_sampled: true,
			sample_rate: null,
			sample_rates: [ { rate: 0.1, call_count: 20 }, { rate: 0.5, call_count: 80 } ],
			estimated_total_calls: 360.0,
		},
	} ) );

	const rates = ctx.find( byClass( 'saltus-sampling-rates' ) );

	assert.ok( rates, 'a window with several rates must disclose them' );

	const rendered = textOf( rates );

	assert.match( rendered, /10% sample/ );
	assert.match( rendered, /20 recorded rows/ );
	assert.match( rendered, /50% sample/ );
	assert.match( rendered, /80 recorded rows/ );
	assert.match( ctx.text(), /several sampling rates/, 'the estimate hint must not claim a single rate' );
} );

test( 'the sampling notice carries no undefined ARIA role', async () => {
	const ctx = mount();
	await ctx.load( metricsPayload( {
		sampling: { is_sampled: true, sample_rate: 0.5, sample_rates: [ { rate: 0.5, call_count: 617 } ], estimated_total_calls: 2468.0 },
	} ) );

	const notice = ctx.find( byClass( 'saltus-metrics-sampling-notice' ) );

	assert.ok( notice, 'the notice must render when sampling is on' );
	assert.strictEqual( notice.props.role, undefined, 'role="note" is not a role assistive technology honours' );
	assert.ok(
		notice.children.some( child => child.type === 'p' ),
		'the notice text must be a paragraph rather than a bare div'
	);

	const roles = ctx.nodes().map( node => node.props.role ).filter( Boolean );

	assert.deepStrictEqual( roles.filter( role => role === 'note' ), [] );
} );

test( 'refetching keeps the previous chart on screen and marks it busy', async () => {
	const ctx = mount();
	await ctx.load();

	ctx.change( 'saltus-range-select', '30' );

	const content = ctx.find( byClass( 'saltus-metrics-content' ) );

	assert.strictEqual( content.props[ 'aria-busy' ], 'true' );
	assert.strictEqual( bars( ctx ).length, 3, 'the operator must not lose the chart while the next window loads' );
} );

test( 'a superseded request is aborted rather than left running', async () => {
	const ctx = mount();
	await flush();

	ctx.change( 'saltus-range-select', '30' );
	await flush();

	assert.strictEqual( ctx.calls.length, 2, 'the range change must issue its own request' );
	assert.match( ctx.calls[ 1 ].url, /range=30/ );
	assert.strictEqual(
		ctx.calls[ 0 ].signal.aborted,
		true,
		'the browser should be told to stop fetching a window nobody is looking at'
	);
	assert.strictEqual( ctx.calls[ 1 ].signal.aborted, false );
} );

test( 'a stale response never overwrites the newest metrics', async () => {
	const ctx = mount();
	await flush();

	ctx.change( 'saltus-range-select', '30' );
	await flush();

	ctx.calls[ 1 ].respond( metricsPayload( { total_calls: 555 } ) );
	await flush();

	assert.match( ctx.text(), /555/, 'the answered request is the one on screen' );

	// The slower first request answers last. Its body describes range=7, which
	// the operator has already left, so it must be discarded — resolving after a
	// newer request does not make it newer.
	ctx.calls[ 0 ].respond( metricsPayload( { total_calls: 999 } ) );
	await flush();

	assert.doesNotMatch( ctx.text(), /999/, 'a superseded response reached the display' );
	assert.match( ctx.text(), /555/, 'the newest response was overwritten' );
} );

test( 'changing the ability supersedes the in-flight request too', async () => {
	const ctx = mount();
	await flush();

	ctx.change( 'saltus-ability-select', 'create_post' );
	await flush();

	assert.match( ctx.calls[ 1 ].url, /ability=create_post/ );
	assert.strictEqual( ctx.calls[ 0 ].signal.aborted, true );

	ctx.calls[ 1 ].respond( metricsPayload( { total_calls: 555 } ) );
	await flush();
	ctx.calls[ 0 ].respond( metricsPayload( { total_calls: 999 } ) );
	await flush();

	assert.doesNotMatch( ctx.text(), /999/ );
	assert.match( ctx.text(), /555/ );
} );

test( 'aborting our own request is not reported as a network error', async () => {
	const calls = [];
	const ctx = mount( {
		// Real fetch rejects an aborted request with an AbortError. That rejection
		// is expected here, not a failure the operator needs to see.
		fetchImpl: ( url, init ) => new Promise( ( resolve, reject ) => {
			const { signal } = init;

			calls.push( { url, signal, respond: payload => resolve( { json: () => Promise.resolve( payload ) } ) } );

			signal.addEventListener( 'abort', () => {
				const error = new Error( 'The operation was aborted.' );
				error.name = 'AbortError';
				reject( error );
			} );
		} ),
	} );
	await flush();

	ctx.change( 'saltus-range-select', '30' );
	await flush();

	assert.doesNotMatch( ctx.text(), /Network error/ );

	calls[ 1 ].respond( metricsPayload( { total_calls: 555 } ) );
	await flush();

	assert.match( ctx.text(), /555/, 'the surviving request must still render' );
	assert.doesNotMatch( ctx.text(), /Network error/ );
} );
