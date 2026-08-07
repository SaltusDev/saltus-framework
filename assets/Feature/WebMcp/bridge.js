/**
 * Saltus WebMCP bridge.
 *
 * Registers the model's public tools with the browser's WebMCP surface so an
 * in-browser AI agent can call them instead of scraping the page. Each tool
 * proxies to the same-origin REST endpoint, which re-validates arguments and
 * enforces the framework's own gating — the browser is never trusted.
 *
 * When no WebMCP surface exists the bridge returns immediately and logs
 * nothing. Chrome ships the API no earlier than 157, and Safari and Firefox
 * have registered no position, so absence is the common case.
 */
( function () {
	'use strict';

	var config = window.saltusWebMcp;

	if ( ! config || ! Array.isArray( config.tools ) || config.tools.length === 0 ) {
		return;
	}

	/**
	 * Resolve the WebMCP surface across API generations.
	 *
	 * Chrome 150+ exposes `document.modelContext`. Chrome 146-149 exposed
	 * `navigator.modelContext`, which 150 keeps as a deprecated alias whose
	 * accessor logs a warning. `document` is probed first so supported
	 * browsers never touch the deprecated path.
	 *
	 * This is the only place either namespace is read.
	 *
	 * @return {object|null} The model context, or null when unsupported.
	 */
	function resolveModelContext() {
		if ( typeof document !== 'undefined' && document.modelContext ) {
			return document.modelContext;
		}

		if ( typeof navigator !== 'undefined' && navigator.modelContext ) {
			return navigator.modelContext;
		}

		return null;
	}

	var modelContext = resolveModelContext();

	if ( ! modelContext || typeof modelContext.registerTool !== 'function' ) {
		return;
	}

	var controller = typeof AbortController === 'function' ? new AbortController() : null;

	/**
	 * Call one tool through the REST endpoint.
	 *
	 * @param {string} name Tool name.
	 * @param {object} args Arguments supplied by the agent.
	 * @return {Promise<object>} The MCP-shaped tool result.
	 */
	function callTool( name, args ) {
		return fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( {
				tool: name,
				arguments: args || {},
			} ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					if ( ! response.ok ) {
						throw new Error( payload && payload.message ? payload.message : 'Tool call failed.' );
					}

					return payload;
				} );
			} )
			.then( function ( payload ) {
				return {
					content: [
						{
							type: 'text',
							text: JSON.stringify( payload.result ),
						},
					],
				};
			} )
			.catch( function ( error ) {
				return {
					isError: true,
					content: [
						{
							type: 'text',
							text: error.message,
						},
					],
				};
			} );
	}

	/**
	 * Register one descriptor with the browser.
	 *
	 * @param {object} descriptor Serialized tool descriptor.
	 */
	function registerTool( descriptor ) {
		var definition = {
			name: descriptor.name,
			description: descriptor.description,
			inputSchema: descriptor.inputSchema,
			annotations: descriptor.annotations || {},
			execute: function ( args ) {
				return callTool( descriptor.name, args );
			},
		};

		var options = controller ? { signal: controller.signal } : undefined;

		try {
			var registration = modelContext.registerTool( definition, options );

			// Registration is async in the current API and rejects with
			// NotAllowedError when the `tools` Permissions Policy blocks it.
			if ( registration && typeof registration.catch === 'function' ) {
				registration.catch( function () {} );
			}
		} catch ( error ) {
			// A browser mid-migration between API shapes should not break
			// the page for the human visitor.
		}
	}

	config.tools.forEach( registerTool );

	// Unregister on teardown so a cached page restored from the back/forward
	// cache does not leave stale tools registered.
	window.addEventListener( 'pagehide', function () {
		if ( controller ) {
			controller.abort();
		}
	} );
} )();
