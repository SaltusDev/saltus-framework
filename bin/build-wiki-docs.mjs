#!/usr/bin/env node
/**
 * Build a flattened GitHub Wiki mirror of the published docs into
 * build/docs/wiki/.
 *
 * The wiki is a flat namespace with no nested routing, no frontmatter support,
 * and no VitePress markdown extensions, so a straight file copy produces broken
 * pages. Each transform below exists for a specific way the wiki renders
 * VitePress source incorrectly:
 *
 *   guides/blocks.md      -> Guides-Blocks.md      (no directories)
 *   ](/guides/features)   -> [[Guides-Features]]   (site-absolute links 404)
 *   ](../mcp/index.md)    -> [[Mcp-Index]]         (relative links 404)
 *   --- frontmatter ---   -> stripped              (renders as a table)
 *   ::: warning ... :::   -> blockquote            (renders as literal ':::')
 *   index.md hero        -> purpose-written Home.md
 *   (no sidebar)          -> generated _Sidebar.md
 *
 * The API reference is 500+ phpDoc HTML files and cannot live in a wiki, so it
 * becomes a link to the site.
 *
 * Sources come from the same srcExclude list the site build uses, so a private
 * doc cannot reach the wiki either.
 */
import {
  existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync,
} from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(dirname(fileURLToPath(import.meta.url)))
const SRC = join(root, 'docs')
const OUT = join(root, 'build/docs/wiki')
const SITE = 'https://docs.saltus.dev'

/** Directories and files never mirrored (private, generated, or infrastructure). */
const SKIP_DIRS = new Set([
  '.vitepress', 'public', 'assets', 'discovery', 'phase10', 'olddocs',
  'planning', 'handoffs', 'notes', 'api',
])
const SKIP_FILES = new Set([
  'index.md',              // replaced by a purpose-written Home.md
  'CURRENT.md', 'HANDOFF.md', 'TESTING_HANDOFF.md',
  'PHASE-10-HIGHWAY.md', 'DOCS-SPLIT-PLAN.md',
])

/** Words that must not be title-cased naively in page names or labels. */
const ACRONYMS = new Map([
  ['ai', 'AI'], ['mcp', 'MCP'], ['wp', 'WP'], ['cli', 'CLI'],
  ['webmcp', 'WebMCP'], ['api', 'API'], ['rest', 'REST'],
])

function titleWord(w) {
  if (!w) return w
  return ACRONYMS.get(w.toLowerCase()) ?? w[0].toUpperCase() + w.slice(1)
}

/** docs-relative path -> flat wiki page name. `guides/blocks.md` -> `Guides-Blocks`. */
function wikiName(relPath) {
  return relPath
    .replace(/\.md$/, '')
    .split('/')
    .map((seg) => seg.split('-').map(titleWord).join('-'))
    .join('-')
}

/** The root project pages have filenames that read as shouting in a nav. */
const PAGE_LABELS = new Map([
  ['PROJECT.md', 'About Saltus'],
  ['CONTEXT.md', 'Architecture & Decisions'],
  ['ROADMAP.md', 'Roadmap'],
  ['getting-started.md', 'Getting Started'],
])

/** Human label for the sidebar, e.g. `guides/wp-cli.md` -> `WP CLI`. */
function pageLabel(relPath) {
  const explicit = PAGE_LABELS.get(relPath)
  if (explicit) return explicit
  const base = relPath.replace(/\.md$/, '').split('/').pop()
  if (base === 'index') {
    const dir = relPath.split('/')[0]
    return `${titleWord(dir)} Overview`
  }
  return base.split('-').map(titleWord).join(' ')
}

function collect(dir, acc = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    const rel = relative(SRC, full)
    if (statSync(full).isDirectory()) {
      if (!SKIP_DIRS.has(entry)) collect(full, acc)
    } else if (entry.endsWith('.md') && !SKIP_FILES.has(rel)) {
      acc.push(rel)
    }
  }
  return acc
}

const pages = collect(SRC).sort()
/** Every route form a link might use -> wiki page name. */
const routeMap = new Map()
for (const rel of pages) {
  const name = wikiName(rel)
  const noExt = rel.replace(/\.md$/, '')
  routeMap.set(noExt, name)                       // guides/blocks
  routeMap.set(`/${noExt}`, name)                 // /guides/blocks
  routeMap.set(rel, name)                         // guides/blocks.md
  routeMap.set(`/${rel}`, name)
  if (noExt.endsWith('/index')) {                 // mcp/index -> also mcp/ and /mcp/
    const dir = noExt.replace(/\/index$/, '')
    routeMap.set(dir, name)
    routeMap.set(`/${dir}`, name)
    routeMap.set(`/${dir}/`, name)
  }
}

/** Resolve a link target relative to the page containing it. */
function resolveTarget(target, fromRel) {
  let t = target.trim()
  if (/^(https?:|mailto:|#)/.test(t)) return null      // external or same-page anchor

  const [pathPart, anchor] = t.split('#')
  if (!pathPart) return null

  let key = pathPart.replace(/\/$/, '') || pathPart
  if (!key.startsWith('/')) {
    // Relative: resolve against the current page's directory.
    const base = dirname(fromRel)
    const parts = (base === '.' ? [] : base.split('/'))
    for (const seg of key.split('/')) {
      if (seg === '.' || seg === '') continue
      if (seg === '..') parts.pop()
      else parts.push(seg)
    }
    key = parts.join('/')
  }

  const stripped = key.replace(/\.md$/, '')
  const name = routeMap.get(key) ?? routeMap.get(stripped) ?? routeMap.get(`/${stripped}`)
  if (!name) return null
  return anchor ? `${name}#${anchor}` : name
}

function transform(body, relPath) {
  let out = body

  // Frontmatter: the wiki renders it as a table. Keep `title` as an H1 if the
  // body has none, so the page is not left untitled.
  const fm = out.match(/^---\n([\s\S]*?)\n---\n/)
  if (fm) {
    const title = fm[1].match(/^title:\s*(.+)$/m)?.[1]?.trim()
    out = out.slice(fm[0].length)
    if (title && !/^#\s/m.test(out)) out = `# ${title}\n\n${out}`
  }

  // Custom containers -> blockquote. Without this the ':::' lines render
  // literally and the callout loses all emphasis.
  out = out.replace(
    /^::: *(tip|warning|danger|info|details)([^\n]*)\n([\s\S]*?)^::: *$/gm,
    (_m, kind, heading, inner) => {
      const label = (heading.trim() || kind[0].toUpperCase() + kind.slice(1)).trim()
      const quoted = inner.trimEnd().split('\n').map((l) => (l ? `> ${l}` : '>')).join('\n')
      return `> **${label}**\n>\n${quoted}\n`
    }
  )

  // Internal links -> [[WikiLinks]]. Unresolvable internal targets become
  // absolute site URLs rather than dead wiki links.
  out = out.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, text, target) => {
    const t = target.trim()
    if (/^(https?:|mailto:|#)/.test(t)) return m
    const name = resolveTarget(t, relPath)
    if (name) return `[${text}](${name.replace(/ /g, '-')})`
    const clean = t.replace(/\.md$/, '')
    return `[${text}](${SITE}${clean.startsWith('/') ? clean : `/${clean}`})`
  })

  return out.trimEnd() + '\n'
}

// --- write output -----------------------------------------------------------

rmSync(OUT, { recursive: true, force: true })
mkdirSync(OUT, { recursive: true })

for (const rel of pages) {
  const body = readFileSync(join(SRC, rel), 'utf8')
  writeFileSync(join(OUT, `${wikiName(rel)}.md`), transform(body, rel))
}

// Home.md — the hero layout in index.md is frontmatter-only and renders as
// nothing useful, so the landing page is written for the wiki specifically.
writeFileSync(
  join(OUT, 'Home.md'),
  `# Saltus Framework

WordPress plugin development framework for Custom Post Types. Define post types,
taxonomies, meta boxes, settings pages, and admin tooling from configuration files.

This wiki mirrors [${SITE}](${SITE}), which is the canonical documentation.

## Start here

- [Getting Started](${wikiName('getting-started.md')}) — install and register the framework
- [Features Reference](${wikiName('guides/features.md')}) — every model config option
- [Architecture](${wikiName('guides/architecture.md')}) — how the framework is put together

## AI surfaces

- [MCP/Abilities Overview](${wikiName('mcp/index.md')}) — the WordPress-native tool surface
- [Permissions](${wikiName('mcp/permissions.md')}) — capability checks and config gating
- [Abilities Reference](${wikiName('mcp/abilities.md')}) — generated, every tool and parameter
- [Client Integration](${wikiName('mcp/clients.md')}) — connecting an AI client

## API reference

The class-level API reference is generated HTML and lives on the site:
[${SITE}/api/reference/](${SITE}/api/reference/)
`
)

// _Sidebar.md — the wiki has no navigation otherwise.
const groups = new Map()
for (const rel of pages) {
  const dir = rel.includes('/') ? rel.split('/')[0] : 'Project'
  const label = dir === 'guides' ? 'Guides' : dir === 'mcp' ? 'MCP/Abilities' : 'Project'
  if (!groups.has(label)) groups.set(label, [])
  groups.get(label).push(`  - [${pageLabel(rel)}](${wikiName(rel)})`)
}

let sidebar = `### [Saltus Framework](Home)\n\n`
for (const [label, items] of [...groups].sort(([a], [b]) =>
  a === 'Project' ? 1 : b === 'Project' ? -1 : a.localeCompare(b)
)) {
  sidebar += `- **${label}**\n${items.sort().join('\n')}\n`
}
sidebar += `- **[API Reference](${SITE}/api/reference/)**\n`
writeFileSync(join(OUT, '_Sidebar.md'), sidebar)

console.log(`build-wiki-docs: ${pages.length} pages + Home + _Sidebar -> build/docs/wiki/`)
