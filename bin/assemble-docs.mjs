#!/usr/bin/env node
/**
 * Stage generated (tier 3) assets into the VitePress source tree so the site
 * build can pick them up, then move the built site to build/docs/site.
 *
 * Two things are staged into docs/public/, which VitePress copies verbatim
 * without parsing:
 *
 *   build/docs/api/  -> docs/public/api/reference/   (phpDocumentor HTML)
 *   .agents/skills/  -> docs/public/downloads/       (the agent skill)
 *
 * The phpDoc output is mounted at /api/reference/ rather than /api/ because
 * docs/api/index.md already owns /api/ as the authored landing page. Both
 * emitted an index.html at the same path before, and the authored one silently
 * won. phpDoc links relatively, so it works at any mount point.
 *
 * Run before `vitepress build`. Idempotent.
 */
import { existsSync, mkdirSync, rmSync, cpSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = dirname(dirname(fileURLToPath(import.meta.url)))

const API_SRC = join(root, 'build/docs/api')
const API_DEST = join(root, 'docs/public/api/reference')
const SKILL_SRC = join(root, '.agents/skills/saltus-mcp/SKILL.md')
const SKILL_DEST = join(root, 'docs/public/downloads/saltus-mcp/SKILL.md')

let staged = 0

// 1. phpDocumentor HTML. Absent on a docs-only build; that is not fatal, the
//    /api/ landing page still renders and links out.
if (existsSync(API_SRC)) {
  rmSync(API_DEST, { recursive: true, force: true })
  mkdirSync(dirname(API_DEST), { recursive: true })
  cpSync(API_SRC, API_DEST, { recursive: true })
  staged += readdirSync(API_DEST).length
  console.log(`staged phpDocumentor output -> docs/public/api/reference/`)
} else {
  console.warn(
    `! build/docs/api missing — run \`composer docs:api\` for the API reference.`
  )
}

// 2. The agent skill. .agents/ is the single source of truth; the download is
//    a copy so the two cannot drift by hand-editing.
if (existsSync(SKILL_SRC)) {
  mkdirSync(dirname(SKILL_DEST), { recursive: true })
  cpSync(SKILL_SRC, SKILL_DEST)
  staged += 1
  console.log('staged saltus-mcp SKILL.md -> docs/public/downloads/')
} else {
  console.warn(`! ${SKILL_SRC} missing — the skill download will 404.`)
}

console.log(`assemble-docs: ${staged} item(s) staged.`)
