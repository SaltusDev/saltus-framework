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

/**
 * The payload an admin screen localizes: nonce-bearing, with a refresh endpoint.
 *
 * @return {object} Admin bridge config.
 */
function adminConfig() {
	return {
		endpoint: 'https://example.test/wp-json/saltus-framework/v1/webmcp/execute',
		nonce: 'nonce-one',
		nonceEndpoint: 'https://example.test/wp-json/saltus-framework/v1/webmcp/nonce',
		surface: 'admin',
		tools: [
			{
				name: 'update_post',
				description: 'Update an entry. Queues the change for human review.',
				inputSchema: { type: 'object', properties: { post_id: { type: 'number' } } },
				annotations: { readOnlyHint: false, untrustedContentHint: true },
			},
		],
	};
}

/** Endpoint name from a URL, for asserting call order. */
function endpointOf( url ) {
	return url.split( '/' ).pop();
}

test( 'sends the nonce as a header when the payload carries one', async () => {
	const { registered, fetchCalls } = runBridge( { config: adminConfig() } );

	await registered[ 0 ].definition.execute( { post_id: 9 } );

	assert.strictEqual( fetchCalls[ 0 ].init.headers[ 'x-wp-nonce' ], 'nonce-one' );
	assert.strictEqual( fetchCalls[ 0 ].init.credentials, 'same-origin' );
} );

test( 'a frontend payload sends no nonce header', async () => {
	const { registered, fetchCalls } = runBridge();

	await registered[ 0 ].definition.execute( {} );

	assert.ok(
		! ( 'x-wp-nonce' in fetchCalls[ 0 ].init.headers ),
		'An anonymous visitor has no session to bind a nonce to.'
	);
} );

test( 'an expired nonce is refreshed and the call retried once, invisibly', async () => {
	const calls = [];

	const { registered } = runBridge( {
		config: adminConfig(),
		fetch: ( url, init ) => {
			calls.push( { endpoint: endpointOf( url ), nonce: init.headers && init.headers[ 'x-wp-nonce' ] } );

			if ( endpointOf( url ) === 'nonce' ) {
				return Promise.resolve( { ok: true, json: () => Promise.resolve( { nonce: 'nonce-two' } ) } );
			}

			// The first execute fails on a stale nonce; the retry succeeds.
			if ( calls.filter( ( call ) => call.endpoint === 'execute' ).length === 1 ) {
				return Promise.resolve( {
					ok: false,
					json: () =>
						Promise.resolve( {
							code: 'saltus_webmcp_invalid_nonce',
							message: 'The security token is missing or expired.',
							data: { status: 403, refresh: true },
						} ),
				} );
			}

			return Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( { tool: 'update_post', result: { requires_review: true } } ),
			} );
		},
	} );

	const result = await registered[ 0 ].definition.execute( { post_id: 9 } );

	assert.deepStrictEqual(
		calls.map( ( call ) => call.endpoint ),
		[ 'execute', 'nonce', 'execute' ],
		'A stale nonce should trigger exactly one refresh and one retry.'
	);
	assert.strictEqual( calls[ 2 ].nonce, 'nonce-two', 'The retry must use the refreshed nonce.' );
	assert.ok( ! result.isError, 'The user should never see the expiry.' );
	assert.deepStrictEqual( JSON.parse( result.content[ 0 ].text ), { requires_review: true } );
} );

test( 'a genuine permission failure is not retried', async () => {
	const calls = [];

	const { registered } = runBridge( {
		config: adminConfig(),
		fetch: ( url ) => {
			calls.push( endpointOf( url ) );

			return Promise.resolve( {
				ok: false,
				json: () =>
					Promise.resolve( {
						code: 'saltus_webmcp_forbidden',
						message: 'You do not have permission to call this tool.',
						data: { status: 403 },
					} ),
			} );
		},
	} );

	const result = await registered[ 0 ].definition.execute( {} );

	assert.deepStrictEqual( calls, [ 'execute' ], 'Retrying a capability failure just burns a request.' );
	assert.strictEqual( result.isError, true );
	assert.strictEqual( result.content[ 0 ].text, 'You do not have permission to call this tool.' );
} );

test( 'a failed nonce refresh reports the original error', async () => {
	const { registered } = runBridge( {
		config: adminConfig(),
		fetch: ( url ) => {
			if ( endpointOf( url ) === 'nonce' ) {
				return Promise.resolve( { ok: false, json: () => Promise.resolve( {} ) } );
			}

			return Promise.resolve( {
				ok: false,
				json: () =>
					Promise.resolve( {
						code: 'saltus_webmcp_invalid_nonce',
						message: 'The security token is missing or expired.',
						data: { status: 403, refresh: true },
					} ),
			} );
		},
	} );

	const result = await registered[ 0 ].definition.execute( {} );

	assert.strictEqual( result.isError, true );
	assert.strictEqual( result.content[ 0 ].text, 'The security token is missing or expired.' );
} );

test( 'a toolchange event re-registers the tool set against a fresh signal', () => {
	const { registered, listeners, aborted } = runBridge( { config: adminConfig() } );

	assert.strictEqual( registered.length, 1 );
	assert.strictEqual( typeof listeners[ 'saltus-webmcp-toolchange' ], 'function' );

	listeners[ 'saltus-webmcp-toolchange' ]( {
		detail: {
			tools: [
				{
					name: 'get_post',
					description: 'Read one entry.',
					inputSchema: { type: 'object', properties: {} },
					annotations: { readOnlyHint: true },
				},
			],
		},
	} );

	// The old set is unregistered before the new one lands, so an agent never
	// sees both generations at once.
	assert.deepStrictEqual( aborted, [ true ] );
	assert.strictEqual( registered.length, 2 );
	assert.strictEqual( registered[ 1 ].definition.name, 'get_post' );
} );

test( 'a toolchange without a payload re-registers the current set', () => {
	const { registered, listeners } = runBridge( { config: adminConfig() } );

	listeners[ 'saltus-webmcp-toolchange' ]();

	assert.strictEqual( registered.length, 2 );
	assert.strictEqual( registered[ 1 ].definition.name, 'update_post' );
} );

test( 'tools registered after a toolchange still unregister on pagehide', () => {
	const { listeners, aborted, registered } = runBridge( { config: adminConfig() } );

	listeners[ 'saltus-webmcp-toolchange' ]();
	listeners.pagehide();

	// Two aborts: one retiring the old generation, one tearing down the new. A
	// stale controller here would leave the replacement tools registered.
	assert.deepStrictEqual( aborted, [ true, true ] );
	assert.notStrictEqual(
		registered[ 1 ].opts.signal,
		registered[ 0 ].opts.signal,
		'The replacement generation needs its own signal.'
	);
} );
