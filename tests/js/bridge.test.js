/**
 * Tests for assets/Feature/WebMcp/bridge.js.
 *
 * The bridge is the only WebMCP component that runs in a browser, so it is
 * exercised here in a fresh VM context per case rather than through PHPUnit.
 * Chrome ships the API no earlier than 157, which makes the absent-API path the
 * common one: it must register nothing and, critically, log nothing.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const vm = require( 'node:vm' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

const BRIDGE = fs.readFileSync(
	path.join( __dirname, '../../assets/Feature/WebMcp/bridge.js' ),
	'utf8'
);

/**
 * Run the bridge against a synthetic browser and report what it touched.
 *
 * @param {object} options            Scenario options.
 * @param {object|null} options.surface  Value for document.modelContext.
 * @param {object|null} options.legacy   Value for navigator.modelContext.
 * @param {object|undefined} options.config Value for window.saltusWebMcp.
 * @param {Function} options.fetch    Stub fetch implementation.
 * @return {object} Observed registrations, listeners, and console output.
 */
function runBridge( options = {} ) {
	const registered = [];
	const listeners = {};
	const consoleOutput = [];
	const aborted = [];
	const fetchCalls = [];

	const record = ( channel ) => ( ...args ) => consoleOutput.push( { channel, args } );

	const config =
		'config' in options
			? options.config
			: {
					endpoint: 'https://example.test/wp-json/saltus-framework/v1/webmcp/execute',
					tools: [
						{
							name: 'search_content',
							description: 'Search published content.',
							inputSchema: { type: 'object', properties: { query: { type: 'string' } } },
							annotations: { readOnlyHint: true, untrustedContentHint: true },
						},
					],
			  };

	const surface =
		'surface' in options
			? options.surface
			: {
					registerTool( definition, opts ) {
						registered.push( { definition, opts } );
						return Promise.resolve();
					},
			  };

	const sandbox = {
		window: {
			saltusWebMcp: config,
			addEventListener( type, handler ) {
				listeners[ type ] = handler;
			},
		},
		document: surface === null ? {} : { modelContext: surface },
		navigator: 'legacy' in options && options.legacy ? { modelContext: options.legacy } : {},
		AbortController: function AbortController() {
			this.signal = { id: 'signal' };
			this.abort = () => aborted.push( true );
		},
		fetch:
			options.fetch ||
			( ( url, init ) => {
				fetchCalls.push( { url, init } );
				return Promise.resolve( {
					ok: true,
					json: () => Promise.resolve( { tool: 'search_content', result: { count: 0 } } ),
				} );
			} ),
		console: {
			log: record( 'log' ),
			warn: record( 'warn' ),
			error: record( 'error' ),
			info: record( 'info' ),
			debug: record( 'debug' ),
		},
	};

	vm.runInNewContext( BRIDGE, sandbox );

	return { registered, listeners, consoleOutput, aborted, fetchCalls, sandbox };
}

test( 'registers one tool per descriptor when a surface exists', () => {
	const { registered } = runBridge();

	assert.strictEqual( registered.length, 1 );
	assert.strictEqual( registered[ 0 ].definition.name, 'search_content' );
	assert.strictEqual( typeof registered[ 0 ].definition.execute, 'function' );
	assert.deepStrictEqual( registered[ 0 ].definition.annotations, {
		readOnlyHint: true,
		untrustedContentHint: true,
	} );
} );

test( 'registers nothing and logs nothing when the API is absent', () => {
	const { registered, consoleOutput } = runBridge( { surface: null } );

	assert.strictEqual( registered.length, 0 );
	assert.deepStrictEqual(
		consoleOutput,
		[],
		'An unsupported browser is the common case and must stay silent.'
	);
} );

test( 'falls back to the deprecated navigator namespace', () => {
	const seen = [];
	const { registered, consoleOutput } = runBridge( {
		surface: null,
		legacy: {
			registerTool( definition ) {
				seen.push( definition.name );
				return Promise.resolve();
			},
		},
	} );

	assert.deepStrictEqual( seen, [ 'search_content' ] );
	assert.strictEqual( registered.length, 0, 'document.modelContext was absent in this scenario.' );
	assert.deepStrictEqual( consoleOutput, [] );
} );

test( 'prefers document over navigator when both are present', () => {
	const legacyCalls = [];
	const { registered } = runBridge( {
		legacy: {
			registerTool() {
				legacyCalls.push( true );
				return Promise.resolve();
			},
		},
	} );

	assert.strictEqual( registered.length, 1 );
	assert.strictEqual(
		legacyCalls.length,
		0,
		'Touching the deprecated accessor logs a warning in Chrome 150+.'
	);
} );

test( 'no-ops when the localized config is missing or empty', () => {
	for ( const config of [ undefined, {}, { tools: [] }, { tools: 'nope' } ] ) {
		const { registered, consoleOutput } = runBridge( { config } );

		assert.strictEqual( registered.length, 0 );
		assert.deepStrictEqual( consoleOutput, [] );
	}
} );

test( 'no-ops when the surface lacks registerTool', () => {
	const { registered, consoleOutput } = runBridge( { surface: { other: true } } );

	assert.strictEqual( registered.length, 0 );
	assert.deepStrictEqual( consoleOutput, [] );
} );

test( 'execute posts the tool call same-origin and unwraps the result', async () => {
	const { registered, fetchCalls } = runBridge();

	const result = await registered[ 0 ].definition.execute( { query: 'earthsea' } );

	assert.strictEqual( fetchCalls.length, 1 );
	assert.strictEqual(
		fetchCalls[ 0 ].url,
		'https://example.test/wp-json/saltus-framework/v1/webmcp/execute'
	);
	assert.strictEqual( fetchCalls[ 0 ].init.method, 'POST' );
	assert.strictEqual( fetchCalls[ 0 ].init.credentials, 'same-origin' );
	assert.deepStrictEqual( JSON.parse( fetchCalls[ 0 ].init.body ), {
		tool: 'search_content',
		arguments: { query: 'earthsea' },
	} );

	assert.strictEqual( result.content[ 0 ].type, 'text' );
	assert.deepStrictEqual( JSON.parse( result.content[ 0 ].text ), { count: 0 } );
	assert.ok( ! result.isError );
} );

test( 'execute sends an empty argument object when the agent passes nothing', async () => {
	const { registered, fetchCalls } = runBridge();

	await registered[ 0 ].definition.execute();

	assert.deepStrictEqual( JSON.parse( fetchCalls[ 0 ].init.body ).arguments, {} );
} );

test( 'a REST error becomes an isError result carrying the message', async () => {
	const { registered } = runBridge( {
		fetch: () =>
			Promise.resolve( {
				ok: false,
				json: () => Promise.resolve( { message: 'Too many tool calls. Try again shortly.' } ),
			} ),
	} );

	const result = await registered[ 0 ].definition.execute( {} );

	assert.strictEqual( result.isError, true );
	assert.strictEqual( result.content[ 0 ].text, 'Too many tool calls. Try again shortly.' );
} );

test( 'a network failure is reported to the agent, not thrown at the page', async () => {
	const { registered } = runBridge( {
		fetch: () => Promise.reject( new Error( 'NetworkError' ) ),
	} );

	const result = await registered[ 0 ].definition.execute( {} );

	assert.strictEqual( result.isError, true );
	assert.strictEqual( result.content[ 0 ].text, 'NetworkError' );
} );

test( 'a throwing registerTool does not break the page', () => {
	const { consoleOutput } = runBridge( {
		surface: {
			registerTool() {
				throw new Error( 'NotSupportedError' );
			},
		},
	} );

	assert.deepStrictEqual(
		consoleOutput,
		[],
		'A browser mid-migration between API shapes must not surface an error to the visitor.'
	);
} );

test( 'a rejected registration promise is swallowed', async () => {
	const { consoleOutput } = runBridge( {
		surface: {
			registerTool() {
				return Promise.reject( new Error( 'NotAllowedError' ) );
			},
		},
	} );

	await new Promise( ( resolve ) => setImmediate( resolve ) );

	assert.deepStrictEqual(
		consoleOutput,
		[],
		'The tools Permissions Policy rejects registration; that is not an error for the page.'
	);
} );

test( 'tools unregister on pagehide so a restored page holds no stale tools', () => {
	const { listeners, aborted, registered } = runBridge();

	assert.strictEqual( typeof listeners.pagehide, 'function' );
	assert.deepStrictEqual( registered[ 0 ].opts.signal, { id: 'signal' } );

	listeners.pagehide();

	assert.deepStrictEqual( aborted, [ true ] );
} );
