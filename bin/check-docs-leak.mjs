#!/usr/bin/env node
/**
 * Fail the build if private (tier 1) content reached the published site.
 *
 * docs.saltus.dev served CURRENT.html, ROADMAP.html, and CONTEXT.html publicly
 * for some time, because VitePress publishes every .md under its source dir
 * unless excluded and nothing excluded them. srcExclude now does, but a config
 * edit or a stray file copy would silently undo that, and the failure mode is
 * invisible — a page that builds fine and simply should not exist.
 *
 * So this asserts the outcome rather than the config: no private route in the
 * built output, and no private text in the local search index (which is a
 * separate leak path — a page can be absent from dist/ while its content is
 * still indexed and searchable).
 *
 * Usage: node bin/check-docs-leak.mjs [dist-dir]
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const dist = process.argv[2] ?? join(root, 'docs/.vitepress/dist')

// Routes that must not exist as published pages.
const FORBIDDEN_ROUTES = [
  'CURRENT.html',
  'HANDOFF.html',
  'TESTING_HANDOFF.html',
  'PHASE-10-HIGHWAY.html',
  'DOCS-SPLIT-PLAN.html',
  'phase10',
  'discovery',
  'olddocs',
  'planning',
  'handoffs',
  'notes',
]

// Distinctive strings from private docs. Chosen to appear in the private
// sources and nowhere in the published set — a hit means content leaked even if
// the route did not.
const FORBIDDEN_STRINGS = [
  'Current: Live Working State',
  'Phase 10: Content Management Pro',
  'TECHNICAL-SPEC',
  'MIGRATION-FAQ',
  'DELIVERY-SUMMARY',
  'Handoff: MCP Error Hints',
  'Discovery: WebMCP',
  'Research complete',
]

if (!existsSync(dist)) {
  console.error(`check-docs-leak: no build found at ${dist}`)
  process.exit(1)
}

const failures = []

for (const route of FORBIDDEN_ROUTES) {
  const target = join(dist, route)
  if (existsSync(target)) {
    failures.push(`private route published: /${route}`)
  }
}

/** Every file in the build, recursively. */
function walk(dir) {
  const out = []
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    if (statSync(full).isDirectory()) {
      out.push(...walk(full))
    } else {
      out.push(full)
    }
  }
  return out
}

// Scan text-bearing output: HTML pages, JS chunks, and the search index.
const scannable = walk(dist).filter((f) => /\.(html|js|json)$/.test(f))

for (const file of scannable) {
  const content = readFileSync(file, 'utf8')
  for (const needle of FORBIDDEN_STRINGS) {
    if (content.includes(needle)) {
      failures.push(`private text "${needle}" found in ${relative(dist, file)}`)
    }
  }
}

if (failures.length > 0) {
  console.error('check-docs-leak: FAILED\n')
  for (const f of [...new Set(failures)]) {
    console.error(`  ✗ ${f}`)
  }
  console.error(
    `\n${failures.length} leak(s). Private docs belong in notes/ and must be listed ` +
      `in srcExclude in docs/.vitepress/config.mjs.`
  )
  process.exit(1)
}

console.log(
  `check-docs-leak: clean — ${scannable.length} files scanned, ` +
    `${FORBIDDEN_ROUTES.length} routes and ${FORBIDDEN_STRINGS.length} markers checked.`
)
