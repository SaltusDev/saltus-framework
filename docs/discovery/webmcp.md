# Discovery: WebMCP (Web Model Context Protocol)

**Date:** 2026-08-07
**Status:** Research complete — informs [Phase 8](../ROADMAP.md#phase-8-webmcp-browser-surface-v24)
**Question:** Should Saltus expose framework capabilities to in-browser AI agents through WebMCP, and if so, how?

---

## Summary

WebMCP lets a web page register typed, callable tools with the browser. An agent running in that
browser discovers them and calls them with JSON arguments instead of screenshotting the page and
guessing where to click. Tool descriptors deliberately reuse MCP's own shape — `name`,
`description`, `inputSchema`, and a `CallToolResult`-style return — so the framing is that the
browser is simply another place MCP runs.

The architectural difference from server-side MCP is where execution happens. A WebMCP tool runs
**in the visitor's own tab**, under their session, with their cookies, in front of their eyes. No
separate backend, no duplicated auth, no server-side user state.

For Saltus this is a *second projection* of a tool surface the framework already owns, not a new
subsystem. The 20 WordPress-native abilities already resolve to 17 REST routes through
`RestBackedToolInterface`. WebMCP is a third consumer of the same definitions, alongside
MCP/Abilities and WP-CLI.

**Recommendation:** worth building, read-only first, on a realistic expectation of zero inbound
agent traffic in the near term. See [Recommendation](#recommendation-for-saltus).

---

## Standards status

Incubating in the **W3C Web Machine Learning Community Group**
([explainer](https://github.com/webmachinelearning/webmcp),
[spec draft](https://webmachinelearning.github.io/webmcp)). First published August 2025 by Brandon
Walderman, Leo Lee, and Andrew Nolan (Microsoft) with David Bokan, Khushal Sagar, and Hannah Van
Opstal (Google). Spec editing is driven largely by Dominic Farolino. The explainer credits MCP-B
(Alex Nahas, Jason McGhee) as prior art.

Pulled from the Chrome Status API for [feature 5117755740913664](https://chromestatus.com/feature/5117755740913664)
rather than from blog coverage, which contradicts itself on version numbers:

| Field | Value |
|-------|-------|
| Dev trial (behind flag) | Chrome 146 |
| Origin trial | Chrome 149 → 156 |
| Ship target | Chrome 157 (desktop, Android, WebView) |
| Chrome status | `Proposed` |
| Firefox position | No signal |
| Safari position | No signal |
| Spec maturity | Community Group incubation |
| `web_feature` id | `document-modelcontext` |

Both WebKit standards-position issues
([#649](https://github.com/WebKit/standards-positions/issues/649), #670) are unanswered by Apple
engineers; #649 was closed as a duplicate. **This is a Chromium-only feature with two of three
engines silent.** Any Saltus implementation must degrade to nothing when the API is absent.

### Namespace churn is the single biggest practical trap

Three generations of API in roughly one year:

1. `navigator.modelContext.provideContext()` — early MCP-B / polyfill convention
2. `navigator.modelContext.registerTool()` — Chrome 146–149
3. `document.modelContext.registerTool()` — Chrome 150+, with `navigator.modelContext` retained as
   a deprecated alias whose accessor logs a console warning

Chrome's docs now state `navigator.modelContext` is deprecated as of Chrome 150. Stable 149 exposed
only the `navigator` form; 150 beta exposed both. Every implementation reviewed — cf-webmcp, the
WebMCP Bridge WordPress plugin, Tallyfy — carries the same probe-`document`-then-fall-back-to-
`navigator` shim. Tallyfy reports this was their single largest time sink, with no documentation
warning about it.

The *consumer* half never moved: it is still `navigator.modelContextTesting.listTools()` and
`.executeTool()`.

**Implication for Saltus:** the namespace probe belongs in exactly one place in our JS bridge, not
scattered across call sites.

---

## API surface

### Imperative

```js
const controller = new AbortController();
await document.modelContext.registerTool({
  name: 'search_models',
  description: 'Search published content across Saltus post type models',
  inputSchema: {
    type: 'object',
    properties: { query: { type: 'string' } },
    required: ['query'],
  },
  annotations: { readOnlyHint: true, untrustedContentHint: true },
  async execute({ query }) {
    return { content: [{ type: 'text', text: await runSearch(query) }] };
  },
}, { signal: controller.signal });   // controller.abort() unregisters
```

Consumption is `getTools()` then `executeTool(tool, argsJsonString)`. Two asymmetries to note:
`inputSchema` goes in as an object but comes back out of `getTools()` as a *serialized JSON string*,
and arguments are passed to `executeTool` as a JSON *string*, not an object. A `toolchange` event
fires on the context object when the tool list mutates.

### Declarative

The more interesting half for a WordPress framework, and the one that gets less coverage. Two
attributes turn an existing form into a tool:

```html
<form toolname="createSupportRequest"
      tooldescription="Submits a request for customer support." action="/submit">
  <label for="firstName">First Name</label>
  <input type=text name=firstName>
  <select name="team" required toolparamdescription="Routes the request.">
    <option value="Customer happiness team">Return my purchase.</option>
  </select>
</form>
```

The schema is *derived, not authored*:

| Markup | Schema output |
|--------|---------------|
| field `name` | property name |
| `<label>` text | property description |
| `aria-description` | property description fallback |
| `required` attribute | entry in the `required` array |
| `<select>` | `anyOf` of `const`/`title` pairs, plus a flat `enum` |
| `toolparamdescription` | explicit property description (highest precedence) |

`SubmitEvent` gains `agentInvoked` (a boolean, so a handler can branch on agent traffic) and
`respondWith(promise)` (whose resolved value is serialized back to the model as tool output;
requires `preventDefault()` first). `:tool-form-active` and `:tool-submit-active` pseudo-classes
let the user *see* the agent filling their form, and `toolactivated` / `toolcancel` events fire on
`window`.

**Implication for Saltus:** because the declarative schema falls out of labels and ARIA attributes,
accessibility work on our metabox and settings markup pays off twice. It is also the cheapest path
to exposing Codestar-rendered forms without hand-authoring schemas for every field type.

### Gating

- The `tools` Permissions Policy, default `self`.
- Cross-origin iframes require `allow="tools"` on the iframe, **and** the registering side must
  list consumers in `exposedTo`, **and** the consumer must name the origin in
  `getTools({ fromOrigins })`. All three, independently.
- Requires origin-isolated documents. Enabling `document.domain` via `Origin-Agent-Cluster: ?0`
  disables the API outright.

---

## Security model — thin, and honest about it

Chrome's [tool security guidance](https://developer.chrome.com/docs/ai/webmcp/secure-tools) states
plainly that "it's impossible to guarantee safety inside of a large language model," and labels its
own advice preliminary. What a site author actually gets:

| Lever | Effect |
|-------|--------|
| `untrustedContentHint` | Marks output as user-generated so the agent applies extra scrutiny |
| `readOnlyHint` | The *only* signal influencing when an agent asks the user to confirm |
| `exposedTo` | Origin scoping — and it cuts both ways (see below) |
| Character budgets | 500 chars per tool description, 150 per parameter, 30 per name, 1.5K per output |

Four findings that matter for framework design:

1. **`exposedTo` is bidirectional.** A named origin gains access both when embedded in your page
   *and* when your page is embedded in theirs. Read-only does not mean harmless: a
   favorites-listing tool leaks user data.
2. **Extensions are not a boundary this API creates.** They can already query and call registered
   tools via content scripts, and with host permissions could inject arbitrary JS regardless.
3. **Authentication at the WebMCP layer is undefined.** There is no story for it. The explainer
   defers its threat model to a spec section, and prompt injection is not discussed there at all.
4. **`requestUserInteraction()` for elicitation is in the spec draft but unsettled.** There is no
   standard confirmation flow to build against yet.

Every serious implementation reviewed responded to (3) the same way: keep everything read-only.

**Implication for Saltus:** this is where the framework has an unusual advantage. The
`EditorialReview` proposal queue delivered in Phase 6B already turns a mutating tool call into a
`pending` proposal awaiting human approval. WebMCP's unsolved confirmation problem is a problem
Saltus already solved for MCP/Abilities, and the same `ProposalService::should_queue()` path
applies unchanged.

---

## How Cloudflare did it

Cloudflare shipped **two separate things on opposite sides of the protocol**, five months apart.
Most coverage conflates them.

### Consumer side — Browser Run, 2026-04-15

WebMCP support in [Browser Run](https://developers.cloudflare.com/browser-run/features/webmcp/)
(the renamed Browser Rendering). Because the API only exists in Chrome beta behind a flag, they
stood up a separate **experimental pool** of Chrome beta instances alongside the standard stable
pool, opted into with `lab=true`:

```bash
wrangler browser create --lab --keepAlive 300
# or: POST .../browser-rendering/devtools/browser?lab=true&keep_alive=300000
```

Only lab sessions expose `navigator.modelContext` and `navigator.modelContextTesting`; production
traffic stays on stable Chrome. Agents reach it over CDP — point `chrome-devtools-mcp` at the
WebSocket endpoint with `--wsHeaders` carrying a bearer token, then call the API through
`evaluate_script`.

Two details from their docs are worth carrying into our design:

- **The tool surface is dynamic and page-scoped.** In their hotel demo the initial tools are
  `view_hotel`, `search_location`, `lookup_amenity`; after `search_location` navigates to results,
  `filter_search_results` appears, then `start_booking`, then `complete_booking`. Their explicit
  instruction to agents is to **re-list after every action and never cache the manifest**. They
  ship a small agent skill encoding exactly that, plus "always prefer WebMCP tools over traditional
  browser automation."
- **Human-in-the-loop is part of the execution contract, not a suggestion.**
  `complete_booking` blocks until the visitor clicks Confirm Reservation in the actual browser.

Stated caveats: lab sessions run Chrome beta and may be unstable, explicitly not for production,
billed like normal usage, and `lab` is not yet supported by `@cloudflare/puppeteer` or
`@cloudflare/playwright` (acquire out of band, attach via `sessionId`).

### Producer side — edge injection, 2026-08-06

Announced during Agents Week ([post](https://blog.cloudflare.com/webmcp/), by Will Rowe). A
dashboard toggle under **Agent Readiness → Labs** gives any zone WebMCP tools with **zero code and
zero origin changes**. Two pieces, both at the edge:

**1. Edge injection via HTMLRewriter.** Every HTML response gets one script tag, with both the tag
and the script served from the edge on the same origin. Works identically for static sites and SPAs.

```html
<script type="module"
        src="/.webmcp/bridge.js"
        data-packs="c2pa,mcp-server-client"
        data-mcp-url="/mcp"></script>
```

**2. The bridge**, an ES module served by a Worker. It probes for the WebMCP surface and returns
immediately if absent — no surface, no behavior change. Otherwise it composes named "packs" into
one tool list and registers each. Static packs declare tools up front; dynamic packs discover
theirs at boot.

The **Site MCP Server pack** is the load-bearing idea. For each tool in the origin's existing
`/mcp` `tools/list`, it registers a browser-side proxy:

```js
document.modelContext.registerTool({
  name: tool.name,
  description: tool.description,
  inputSchema: tool.inputSchema,
  execute: async (args) => {
    const res = await fetch(mcpUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/call",
                             params: { name: tool.name, arguments: args } }),
    });
    const { result } = await res.json();
    return result;
  },
});
```

That is the move worth stealing: **if you already have a server-side MCP endpoint, you get a WebMCP
surface for free, and it inherits the visitor's authenticated session** through
`credentials: "same-origin"`. It sidesteps WebMCP's undefined auth story by borrowing the browser's.

It also means an agent-invoked tool call executes as the logged-in user against your own endpoint,
which is worth thinking hard about before enabling.

The second pack, Content Credentials (C2PA), provides `scan_images_c2pa` and `inspect_image_c2pa` —
a plain TypeScript reader parsing the first few KB of provenance metadata. Every result carries
`signatureVerified: false`, because they decode claims without cryptographically verifying them and
refuse to let an agent mistake one for the other. Good instinct, and a pattern worth copying
wherever Saltus returns data it has not itself validated.

In the preview, **every tool runs entirely in the visitor's browser with no round trip to
Cloudflare.** The Worker is deliberate headroom for future packs that need server compute.

### Why the sequencing matters

Cloudflare built the *consumer* (Browser Run can drive any WebMCP site), then the *producer* (edge
injection makes any zone a WebMCP site), and separately made server-side MCP trivial to host —
[MCP spec 2026-07-28 went fully stateless](https://blog.cloudflare.com/mcp-v2/), dropping the
`initialize` handshake and `Mcp-Session-Id`, and adding `Mcp-Method` / `Mcp-Name` headers so
gateways and WAFs can route on headers instead of parsing JSON-RPC bodies. `McpAgent`-on-Durable-
Objects is no longer required merely to speak the protocol.

So a Worker serves `/mcp` cheaply, and the edge bridge automatically republishes it into every
visitor's browser. Both ends of every MCP conversation, plus the pipe between them. No other vendor
currently holds all three pieces.

Their stated rationale is worth recording because it is the strongest argument for doing this at
all: the web assumed a human reader, the dominant answer to agent traffic has been crawling, and
crawling copies content server-side while returning neither traffic nor credit. Their own Radar
data puts ClaudeBot's crawl-to-refer ratio at roughly 10,600:1. WebMCP tools keep the visitor on the
origin site, in their browser, under the site's branding.

---

## Other platform implementations

### Shopify — the largest real deployment

[Eleven WebMCP tools on every Liquid storefront](https://shopify.dev/docs/api/web-mcp) plus the
Hydrogen developer preview, with no merchant setup. Shipped quietly with no announcement;
[reverse-engineered by nekuda](https://nekuda.substack.com/p/breaking-shopify-stores-are-getting)
as `cdn.shopify.com/storefront/webmcp/webmcp-0.0.3.js`, confirmed live on Reebok, Alo Yoga, Fenty
Beauty, Rhode, and Steve Madden among others. Reproducing the finding is a `grep webmcp` on page
source.

| Category | Tools |
|----------|-------|
| Read | `search_catalog`, `browse_store`, `get_product`, `show_variant`, `get_cart`, `search_shop_policies_and_faqs` |
| Write (page-steering) | `update_cart`, `cancel_cart`, `proceed_to_checkout`, `manage_orders` |
| Money-moving | *none — the shopper still completes the purchase* |

Three implementation details worth copying:

1. **Writes route through existing storefront actions** (`Shopify.actions`), so agent-driven changes
   are indistinguishable from shopper-driven ones and existing theme behavior — cart drawer opening,
   apps listening — fires unchanged. The Saltus analogue is routing WebMCP writes through the same
   shared service classes the REST controllers and WP-CLI commands already use, never a parallel
   path.
2. **`update_cart` refuses to mutate on ambiguity**, returning clarifying options instead of
   guessing.
3. **No money-moving tools at all.** The read/steer/transact split is the deployed consensus on
   where the safe line sits.

Shopify also runs a parallel remote `/api/mcp` for off-site agents like ChatGPT, so there are two
paths into the same store — the same dual-surface shape Saltus would have.

One caution: Shopify's tool set is *uniform across all page types*, contradicting the dynamic
per-page-state model Cloudflare's docs push, and nekuda reports some tools break on pages where
they do not apply. Deployment simplicity beat spec fidelity. Saltus should scope tools to the
current model/page rather than repeat this.

### WordPress — WebMCP Bridge plugin

[WebMCP Bridge](https://wordpress.org/plugins/webmcp-bridge/) (~400 active installs) is the most
complete non-platform implementation found and structurally the closest to what Saltus would build.
Its changelog is a free list of mistakes to skip.

| Concern | Their approach |
|---------|----------------|
| Tool registry | PHP singleton grouped by category, catching `Throwable` not just `Exception` |
| Transport | REST at `/wp-json/webmcp-bridge/v1/` with `manifest`, `execute`, `nonce`, `discovery` |
| Fallback | JS bridge falls back to `window.webmcpBridgeTools` plus plain REST when no native API |
| Read auth | Public by default, mirroring WP REST API defaults |
| Write auth | WordPress nonce fetched from the `nonce` endpoint; filterable tool list |
| Rate limiting | **Global**, not per-IP — deliberately, to resist proxy rotation |
| Cache survival | Link headers moved from `send_headers` to the `wp_headers` filter, plus `wp_head` `<link>` tags so discovery survives full-page caches when PHP never runs; `Vary: Accept` added so Markdown and HTML responses cache separately |
| Data leakage | Removed `admin_email` from site info and author display names from posts to stop username leakage |
| Injection | Sanitizes Markdown output specifically against stored XSS *and* prompt injection |

The cache-survival finding is the one most likely to bite Saltus: any discovery surface emitted only
from a PHP action will vanish behind WP Rocket, LiteSpeed, or W3 Total Cache.

### MCP-B / `@mcp-b`

The prior art the spec credits. Polyfill, SDK, plus a browser extension brokering between an
external MCP client and the in-page registry over Chrome's tabs API; tab and iframe transports; a
`useWebMCP` React hook; and a CDP bridge for Claude Code and Cursor. This is how page tools are
reachable *today* without waiting for Chrome stable — relevant for how we test.

### Framework and tooling support

React via `usewebmcp`; Angular binds registration to the DI lifecycle and can turn Signal Forms
into tools. Both experimental. Google's own tooling is the Model Context Tool Inspector extension
(list, invoke, validate schemas), explicitly *separate* from Gemini in Chrome — and Chrome's docs
stop short of claiming Gemini in Chrome consumes WebMCP tools. Lighthouse 13.3.0 added an Agentic
Browsing category that scores pages on tool registration.

---

## Adoption reality

This is the finding that should temper scope and expectations.

The [freeCodeCamp survey](https://freecodecamp.org/news/a-developers-guide-to-webmcp) scanned
111,076 of the top 200,000 domains via Cloudflare Radar (week of 2026-05-17 to 2026-05-23) and
found **zero** WebMCP implementations. Their framing: it "is not struggling to reach 1%. It has not
started yet."

The pattern across the 17 standards they tracked is the actual insight — passive permission signals
get adopted, active capability standards do not:

| robots.txt | llms.txt / ai.txt | Sitemap | MCP Server Card | A2A Agent Card | WebMCP |
|---|---|---|---|---|---|
| 83% | 79% | 68% | 0.11% | 0.008% | 0% |

The demand side is equally empty:

- chudi.dev's tools: 93 days live, zero external agent calls.
- Tallyfy shipped four production tools on the origin trial and reports no agent invocations either
  — verified callable by their own headless probe, never called by anyone else's agent.

Tallyfy's honest summary is the right frame for us: cost was a handful of engineering days, mostly
accessibility work worth doing regardless, and "if your buyers aren't near agentic browsing,
discovery fundamentals return more per hour."

Also worth noting that Google shipped the Lighthouse scoring audit *before* adoption started —
"the platform owner is building the scoreboard before the game has started."

---

## Recommendation for Saltus

**Build it, read-only first, and size the investment to an expectation of zero inbound agent
traffic in this cycle.** The value is threefold: it costs little because the tool definitions
already exist, it produces an artifact (Lighthouse Agentic Browsing score) that consuming plugins
can point at today, and the accessibility work the declarative API rewards is overdue anyway.

Three design decisions follow directly from the findings above.

### 1. Generate the browser surface from the existing tool registry

Cloudflare's proxy-your-own-MCP-server pattern is the strongest idea in the space, and Shopify
independently arrived at the same shape (WebMCP tools proxying the same `/api/mcp`). It makes
WebMCP a *projection* of a surface we already maintain rather than a second surface to keep in
sync.

Saltus already has the pieces: `ToolInterface` for name/description/`get_parameters()`,
`RestBackedToolInterface` for the REST dispatch mapping, and `ToolContributor` for per-feature
registration. A WebMCP manifest is a serialization of the same definitions, and the JS bridge
becomes a same-origin `fetch` proxy per tool.

**But a pure projection is not sufficient.** All 20 existing abilities gate on `edit_posts` or
narrower, so projecting them verbatim gives an anonymous visitor an empty tool list. Phase 8
therefore needs *both*: the projection mechanism, and a small set of new public-safe read tools
scoped to published content.

### 2. Read-only in 8A; writes through the existing proposal queue in 8B

The deployed consensus (Shopify shipping no money-moving tools, Tallyfy and chudi.dev shipping
read-only, undefined WebMCP auth) says reads are the defensible surface right now.

Writes are still worth planning, because Saltus can answer the question WebMCP has not: Phase 6B's
`EditorialReview` / `ProposalService::should_queue()` already converts a mutating tool call into a
`pending` proposal awaiting human approval. Routing WebMCP writes down that same path turns the
spec's missing confirmation model into a framework feature rather than a gap we have to invent
around.

### 3. Degrade to nothing, and never trust the browser

With Safari and Firefox silent and Chrome shipping no earlier than 157, absence is the common case.
The bridge must no-op cleanly when `document.modelContext` is missing — Cloudflare's bridge returns
immediately, and that is the right behavior. The namespace probe lives in one place. And because
tool arguments arrive from an LLM, every WebMCP call must re-validate server-side through the same
`src/MCP/Validation` path as an ability call: the browser is an untrusted client, not a trusted
caller.

### What not to do

- **Do not hand-author a parallel tool set.** Two sets of definitions will drift.
- **Do not expose write tools without the proposal queue**, on the strength of a `readOnlyHint`
  annotation. The annotation is a hint to the agent, not an enforcement mechanism.
- **Do not emit discovery only from a PHP action.** Full-page caches mean PHP never runs.
- **Do not cache a tool manifest client-side** across navigations; scope tools to the current page.
- **Do not promise traffic.** No observed deployment has recorded an external agent call yet.

---

## Sources

**Cloudflare:** [Give any website a WebMCP interface](https://blog.cloudflare.com/webmcp/) ·
[Browser Run WebMCP docs](https://developers.cloudflare.com/browser-run/features/webmcp/) ·
[Browser Run changelog 2026-04-15](https://developers.cloudflare.com/changelog/post/2026-04-15-br-webmcp/) ·
[The next generation of MCP](https://blog.cloudflare.com/mcp-v2/)

**Standard:** [W3C WebMCP explainer](https://github.com/webmachinelearning/webmcp) ·
[spec draft](https://webmachinelearning.github.io/webmcp) ·
[Chrome Status 5117755740913664](https://chromestatus.com/feature/5117755740913664) ·
[WebKit standards-position #649](https://github.com/WebKit/standards-positions/issues/649)

**Chrome docs:** [WebMCP overview](https://developer.chrome.com/docs/ai/webmcp) ·
[Imperative API](https://developer.chrome.com/docs/ai/webmcp/imperative-api) ·
[Declarative API](https://developer.chrome.com/docs/ai/webmcp/declarative-api) ·
[Tool security](https://developer.chrome.com/docs/ai/webmcp/secure-tools) ·
[Best practices](https://developer.chrome.com/docs/ai/webmcp/best-practices) ·
[Origin trial announcement](https://developer.chrome.com/blog/ai-webmcp-origin-trial)

**Implementations:** [Shopify WebMCP tools](https://shopify.dev/docs/api/web-mcp) ·
[nekuda: Shopify stores are getting WebMCP](https://nekuda.substack.com/p/breaking-shopify-stores-are-getting) ·
[WebMCP Bridge WordPress plugin](https://wordpress.org/plugins/webmcp-bridge/) ·
[MCP-B docs](https://docs.mcp-b.ai/llms.txt) ·
[cf-webmcp browser support](https://github.com/basgr/cf-webmcp/blob/main/docs/browser-support.md)

**Adoption:** [freeCodeCamp: Shipping a 0% Adoption Standard](https://freecodecamp.org/news/a-developers-guide-to-webmcp) ·
[Tallyfy agentic browsing case study](https://tallyfy.com/agentic-browsing-case-study/)
