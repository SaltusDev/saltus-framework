/**
 * Saltus WebMCP bridge.
 *
 * Registers the model's tools with the browser's WebMCP surface so an
 * in-browser AI agent can call them instead of scraping the page. Each tool
 * proxies to the same-origin REST endpoint, which re-validates arguments and
 * enforces the framework's own gating — the browser is never trusted.
 *
 * On admin screens the payload carries a REST nonce. A screen left open beside
 * an agent conversation will outlive that nonce, so a rejected call triggers one
 * silent refresh and retry rather than surfacing a failure the user would have
 * to fix by reloading.
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

	var nonce = typeof config.nonce === 'string' ? config.nonce : '';

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
	 * Fetch a replacement nonce for the current session.
	 *
	 * @return {Promise<string>} The new nonce, or an empty string on failure.
	 */
	function refreshNonce() {
		if ( ! config.nonceEndpoint ) {
			return Promise.resolve( '' );
		}

		return fetch( config.nonceEndpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: nonce ? { 'x-wp-nonce': nonce } : {},
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( payload ) {
				nonce = payload && typeof payload.nonce === 'string' ? payload.nonce : '';

				return nonce;
			} )
			.catch( function () {
				// The session is gone or the network failed. The caller reports the
				// original error, which is more useful to the agent than this one.
				return '';
			} );
	}

	/**
	 * POST one tool call to the execute endpoint.
	 *
	 * @param {string} name Tool name.
	 * @param {object} args Arguments supplied by the agent.
	 * @return {Promise<object>} Resolved response and parsed payload.
	 */
	function postTool( name, args ) {
		var headers = { 'content-type': 'application/json' };

		if ( nonce ) {
			headers[ 'x-wp-nonce' ] = nonce;
		}

		return fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: JSON.stringify( {
				tool: name,
				arguments: args || {},
			} ),
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				return { response: response, payload: payload };
			} );
		} );
	}

	/**
	 * Whether a rejected call is one a fresh nonce would fix.
	 *
	 * Keyed on the server's own `refresh` flag rather than the status code: a 403
	 * is also how a genuine capability failure arrives, and retrying that would
	 * just burn a second request to be told no again.
	 *
	 * @param {object} payload Parsed error payload.
	 * @return {boolean} True when a retry is worth attempting.
	 */
	function isStaleNonce( payload ) {
		if ( ! payload ) {
			return false;
		}

		if ( payload.code === 'saltus_webmcp_invalid_nonce' ) {
			return true;
		}

		return !! ( payload.data && payload.data.refresh );
	}

	/**
	 * Call one tool through the REST endpoint, refreshing a stale nonce once.
	 *
	 * @param {string} name Tool name.
	 * @param {object} args Arguments supplied by the agent.
	 * @return {Promise<object>} The MCP-shaped tool result.
	 */
	function callTool( name, args ) {
		return postTool( name, args )
			.then( function ( first ) {
				if ( first.response.ok ) {
					return first;
				}

				if ( ! isStaleNonce( first.payload ) ) {
					return first;
				}

				// Exactly one retry. A loop here would hammer the endpoint when the
				// session itself has ended rather than merely aged out.
				return refreshNonce().then( function ( fresh ) {
					return fresh ? postTool( name, args ) : first;
				} );
			} )
			.then( function ( result ) {
				if ( ! result.response.ok ) {
					throw new Error(
						result.payload && result.payload.message
							? result.payload.message
							: 'Tool call failed.'
					);
				}

				return {
					content: [
						{
							type: 'text',
							text: JSON.stringify( result.payload.result ),
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

	/**
	 * Re-register the tool set for the current state of the page.
	 *
	 * Called when the page tells us its available tools changed — a model saved
	 * on the editor screen, say, which can gain or lose an ability. Aborting the
	 * previous signal unregisters the old set, and the browser fires its own
	 * `toolchange` event so a listening agent re-reads the list rather than
	 * planning against tools that no longer exist.
	 *
	 * @param {Array|undefined} tools Replacement descriptors, or the current set.
	 */
	function updateTools( tools ) {
		var next = Array.isArray( tools ) ? tools : config.tools;

		if ( controller ) {
			controller.abort();
			controller = typeof AbortController === 'function' ? new AbortController() : null;
		}

		config.tools = next;
		next.forEach( registerTool );
	}

	// Exposed so a model's own admin script can announce a changed tool set
	// without reaching into the bridge's internals.
	window.addEventListener( 'saltus-webmcp-toolchange', function ( event ) {
		updateTools( event && event.detail ? event.detail.tools : undefined );
	} );

	// Unregister on teardown so a cached page restored from the back/forward
	// cache does not leave stale tools registered.
	window.addEventListener( 'pagehide', function () {
		if ( controller ) {
			controller.abort();
		}
	} );
} )();
