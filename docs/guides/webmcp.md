# WebMCP

WebMCP lets an AI agent running inside the visitor's browser call typed functions on your site
instead of scraping the rendered page. Saltus projects the tool definitions it already owns into
that surface, so a model with `webmcp` enabled gains an agent-callable API without you authoring a
second tool system.

This is opt-in per model and off by default. No Saltus site gains a public agent surface without
explicit config.

::: warning Read this before enabling it
The tools below are callable by **unauthenticated visitors**. They expose only published,
publicly-visible content — the same data a visitor could read by browsing the site — but they make
it queryable in bulk and by keyword. Enable it per model, and check what your meta fields contain.
:::

## Browser support

Chrome ships the API no earlier than 157. Safari and Firefox have registered no position. Absence
is the common case, and the bridge treats it that way: when no WebMCP surface exists it returns
immediately, registers nothing, and logs nothing. There is no console noise on unsupported
browsers, and no error for you to suppress.

See [Discovery: WebMCP](/discovery/webmcp) for the standards status this design is built against.

## Enabling it

`webmcp` is a top-level model key, consistent with `ai_context`:

```yaml
name: Book
webmcp:
  enabled: true
  frontend: true
```

The shorthand `webmcp: true` means the same thing.

| Key | Default | Effect |
|---|---|---|
| `enabled` | `false` | Master switch for the model |
| `frontend` | `false` | Register tools on public model views |
| `admin` | `false` | Reserved for 8B — admin screens, not yet implemented |
| `tools` | all | Optional allowlist of tool names |

To narrow the surface to specific tools:

```yaml
webmcp:
  enabled: true
  frontend: true
  tools:
    - search_content
    - get_content
```

A model is only eligible if its post type is publicly queryable. A non-public post type with
`webmcp.enabled: true` registers nothing — the policy filters it out before the manifest is built.

## Tool reference

<!-- BEGIN AUTO-GENERATED WEBMCP TOOLS -->
These tools are registered on public frontend views for every model with
`webmcp: { enabled: true, frontend: true }`. All of them are read-only and return
only published, publicly-visible content.

| Tool | Description | Parameters |
|---|---|---|
| `filter_content` | List published entries of one content type, optionally narrowed to a category or tag. Returns results plus a link to the matching page. | `post_type`\*, `taxonomy`, `term`, `orderby`, `limit` |
| `get_content` | Get the full details of one published entry by its id, including its public custom fields. | `id`\* |
| `list_content_models` | List the kinds of content published on this site, with the filters available for each. Call this first to learn what can be searched. | — |
| `list_taxonomy_terms` | List the categories or tags available for a content type, so results can be filtered by one of them. | `post_type`\*, `taxonomy` |
| `search_content` | Search published content on this site by keyword. Returns matching entries with their title, excerpt, and link. | `query`\*, `post_type`, `limit` |

### `filter_content`

List published entries of one content type, optionally narrowed to a category or tag. Returns results plus a link to the matching page.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `post_type` | string | yes | The content type to list. |
| `taxonomy` | string | no | Taxonomy to filter by, from list_taxonomy_terms. |
| `term` | string | no | Term slug to filter by. |
| `orderby` | string (date, title, menu_order) | no | Sort order for results. |
| `limit` | integer | no | Maximum results, up to 20. |

### `get_content`

Get the full details of one published entry by its id, including its public custom fields.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | The entry id, as returned by search_content. |

### `list_content_models`

List the kinds of content published on this site, with the filters available for each. Call this first to learn what can be searched.

Takes no parameters.

### `list_taxonomy_terms`

List the categories or tags available for a content type, so results can be filtered by one of them.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `post_type` | string | yes | The content type whose taxonomies to list. |
| `taxonomy` | string | no | Restrict output to one taxonomy. |

### `search_content`

Search published content on this site by keyword. Returns matching entries with their title, excerpt, and link.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `query` | string | yes | Words to search for. |
| `post_type` | string | no | Restrict results to one content type. |
| `limit` | integer | no | Maximum results, up to 20. |

### Budgets applied to every tool

| Limit | Value | Applied by |
|---|---|---|
| Tool name | 30 characters | `ManifestBuilder` — a longer name is rejected, not truncated |
| Tool description | 500 characters | `ManifestBuilder` — truncated on a word boundary |
| Parameter description | 150 characters | `ManifestBuilder` — truncated on a word boundary |
| Serialized result | 1500 characters | `ResultBudget` — entries dropped, then strings clipped |
<!-- END AUTO-GENERATED WEBMCP TOOLS -->

## What the browser can and cannot reach

Tools are page-scoped rather than a single uniform set. A single-entry view registers no
`filter_content` or `list_taxonomy_terms`, since neither has anything to act on there.

Only published content surfaces. Every query pins `post_status` to `publish` and excludes
password-protected posts, so no draft, pending, private, or gated entry can appear through a tool
regardless of the arguments an agent sends.

Meta fields are filtered, not passed through. `PublicFieldFilter` requires a field to be
REST-registered by the model before it is eligible, then rejects anything whose key suggests a
secret. Use the filter below to tighten or widen that.

## Security model

The browser is an untrusted client. Arguments arrive from a language model, so every call
re-validates server-side through the same `src/MCP/Validation` path an ability call uses. The
`readOnlyHint` annotation is a hint to the agent about when to ask the user for confirmation — it is
never an enforcement mechanism, and Saltus does not treat it as one.

Every tool result carries `untrustedContentHint`, because post content is user-generated and an
agent should scrutinize it rather than follow instructions found inside it.

### Rate limiting

`/webmcp/execute` is throttled per client, at 60 calls per 60 seconds by default. Logged-in callers
are keyed by user id; everyone else is keyed by a salted hash of `REMOTE_ADDR`, so no raw visitor IP
reaches the audit table.

The rate limiter is the only cross-request state in the WebMCP surface. Each call appends a timestamp
to the client's sliding window, stored as a WordPress transient with a 60-second TTL. The transient
is keyed `saltus_mcp_rate_<sha256(client_identifier)>`. If the transient expires or the cache layer
is flushed, the limit resets for that client. This is deliberate: rate limiting requires memory
across requests, and transients provide TTL-scoped ephemeral storage without introducing a persistent
session layer.

Forwarded headers are deliberately ignored. `X-Forwarded-For` is caller-supplied, so honoring it by
default would let an agent reset its own limit by varying one header. Behind a proxy or CDN that
terminates traffic, resolve the real client yourself:

```php
add_filter(
	'saltus/framework/webmcp/client_identifier',
	function ( string $identifier ): string {
		// Only trust a forwarding header your own edge sets.
		$edge = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';

		return $edge === '' ? $identifier : 'webmcp:ip:' . hash( 'sha256', $edge );
	}
);
```

### Output budgets

Agents apply their own output limits and degrade silently past them: an oversized result is cut
mid-token by the client, leaving the agent with malformed JSON and no signal that anything was
dropped. Saltus clamps results before they leave the server. Entries are dropped from the longest
list first, then long strings are clipped, and `truncated: true` is set so the agent knows to
narrow its query. Sibling `count` values are corrected to match what actually shipped.

### Audit trail

Every invocation is logged with the resolved client identifier, prefixed `webmcp:` so browser
traffic stays separable from WordPress-native ability calls in the audit table. Rate-limited and
validation-rejected calls are recorded too, not just successful ones.

### Request flow

When an agent calls a tool, the bridge sends `POST /saltus-framework/v1/webmcp/execute` with
`{ tool: "search_content", arguments: { query: "earthsea" } }`. Here's what happens on the server:

1. **Parse request** — extract the tool name and arguments from JSON body
2. **Resolve client** — `ClientIdentity` determines who's calling: user id if logged in, otherwise a salted SHA-256 hash of `REMOTE_ADDR` truncated to 32 characters
3. **Rate limit check** — looks up the client's sliding window (a transient keyed `saltus_mcp_rate_<hash>`). If they've made 60 calls in the last 60 seconds, returns `429 Too Many Requests` and logs `rate_limited`. The check happens before validation, so malformed requests count against the window
4. **Find tool** — searches the registered tool list for a matching name. Returns `404` if not found
5. **Re-validate arguments** — runs the request arguments through `Validator::validate()` against the tool's schema, even though the browser already validated. Returns `400` if invalid, logs `validation_error`
6. **Permission check** — calls `$tool->has_permission()`. For public read tools this always returns true. Returns `403` if denied
7. **Execute** — calls `$tool->execute( $args )`. For `search_content` this runs a `WP_Query` pinned to `post_status=publish` and the public models
8. **Clamp result** — `ResultBudget::apply()` trims the serialized result to 1500 characters if needed. Drops entries from the longest list first, then clips long strings on word boundaries, and sets `truncated: true` only if something was actually removed. Sibling `count` values are corrected to match what shipped
9. **Record to audit** — logs the call with client identifier, tool name, arguments, and `success` status
10. **Return JSON** — `{ tool: "search_content", result: { count: 3, results: [...], truncated: false } }`

Every step is request-scoped except the rate limit check (step 3), which reads and writes a transient that expires after 60 seconds. No result caching exists — every call queries the database fresh.

The manifest route (`GET /webmcp/manifest`) is page-scoped: it accepts `?post_type=book` and returns only tools that model allows. The execute route is not page-scoped — it searches across all frontend-enabled models, so an agent can call any tool any model permits regardless of which page's manifest it read. Rate limiting and validation apply universally.

## REST routes

| Route | Method | Purpose | Permission |
|---|---|---|---|
| `/saltus-framework/v1/webmcp/manifest` | GET | Tool descriptors for the current context | Public when a model enables frontend WebMCP; otherwise 404 |
| `/saltus-framework/v1/webmcp/execute` | POST | Invoke one tool by name with validated args | Public for read tools, rate-limited per client |

The bridge does not call `/manifest` on page load. Descriptors are localized into the page, so
discovery costs no network round trip.

## Filters

| Filter | Purpose |
|---|---|
| `saltus/framework/webmcp/tools` | Add or remove tool instances before projection |
| `saltus/framework/webmcp/manifest` | Adjust the final descriptor list, per post type |
| `saltus/framework/webmcp/public_fields` | Control which meta fields a public tool may return |
| `saltus/framework/webmcp/client_identifier` | Resolve the real client behind a proxy or CDN |
| `saltus/framework/webmcp/output_budget` | Raise or lower the result character budget |

Restricting the public field set:

```php
add_filter(
	'saltus/framework/webmcp/public_fields',
	function ( array $fields, string $post_type ): array {
		if ( $post_type !== 'book' ) {
			return $fields;
		}

		return array_values(
			array_filter(
				$fields,
				fn( array $field ): bool => $field['path'] !== 'internal_notes'
			)
		);
	},
	10,
	2
);
```

## Regenerating this page

The tool reference section is generated from the tool classes:

```bash
composer docs:webmcp
```

Run it whenever a tool is added, renamed, or has its parameters changed. The section between the
`AUTO-GENERATED` markers is overwritten; the prose around it is not.

## Not in this release

Write tools, admin-screen registration, cross-origin `exposedTo` delegation, and the declarative
forms API are all out of scope for the frontend surface. When writes arrive they will not mutate
directly — each one will create a proposal through the editorial review queue described in
[Architecture](/guides/architecture), returning its id and review URL to the agent, because WebMCP
has no settled confirmation or authentication model to build against.
