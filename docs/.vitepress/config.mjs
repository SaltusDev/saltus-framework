import { defineConfig } from 'vitepress'

// Shared by the three project pages, which sit at the docs root rather than in
// a directory, so they cannot be matched by a single sidebar path prefix.
const PROJECT_SIDEBAR = [
  {
    text: 'Project',
    items: [
      { text: 'About', link: '/PROJECT' },
      { text: 'Architecture & Decisions', link: '/CONTEXT' },
      { text: 'Roadmap', link: '/ROADMAP' },
    ],
  },
]

export default defineConfig({
  title: 'Saltus Framework',
  description: 'WordPress plugin development framework for Custom Post Types',
  lang: 'en-US',
  base: '/',

  // Private working docs. These live under notes/ and must never be published.
  // Paths are kept here as a belt-and-braces guard: even if a stray copy lands
  // back under docs/, VitePress will refuse to build a page for it.
  // bin/check-docs-leak.mjs asserts every entry is absent from the built site
  // and from the local search index.
  srcExclude: [
    'CURRENT.md',
    'HANDOFF.md',
    'TESTING_HANDOFF.md',
    'PHASE-10-HIGHWAY.md',
    'DOCS-SPLIT-PLAN.md',
    'phase10/**',
    'olddocs/**',
    'discovery/**',
    'planning/**',
    'handoffs/**',
    'notes/**',
  ],

  ignoreDeadLinks: [
    /^\/downloads\//,
  ],

  head: [
    ['link', { rel: 'icon', href: '/favicon.ico' }],
  ],

  themeConfig: {
    logo: '/logo.png',
    logoLink: 'https://saltus.dev',

    nav: [
      { text: 'Getting Started', link: '/getting-started' },
      {
        text: 'Guides',
        items: [
          { text: 'Features', link: '/guides/features' },
          { text: 'Blocks', link: '/guides/blocks' },
          { text: 'Frontend', link: '/guides/frontend' },
          { text: 'Relationships', link: '/guides/relationships' },
          { text: 'Field Security', link: '/guides/field-security' },
          { text: 'AI Context', link: '/guides/ai-context' },
          { text: 'AI Assistants', link: '/guides/ai-assistants' },
          { text: 'WP-CLI', link: '/guides/wp-cli' },
          { text: 'WebMCP', link: '/guides/webmcp' },
          { text: 'Architecture', link: '/guides/architecture' },
          { text: 'Build & Setup', link: '/guides/build' },
        ],
      },
      {
        text: 'MCP/Abilities',
        link: '/mcp/index',
      },
      {
        text: 'Project',
        items: [
          { text: 'About', link: '/PROJECT' },
          { text: 'Architecture & Decisions', link: '/CONTEXT' },
          { text: 'Roadmap', link: '/ROADMAP' },
          { text: 'Accessibility', link: '/ACCESSIBILITY' },
        ],
      },
      {
        text: 'API Reference',
        link: '/api/index',
      },
    ],

    sidebar: {
      '/getting-started': [
        {
          text: 'Getting Started',
          items: [
            { text: 'Quick Start', link: '/getting-started' },
          ],
        },
      ],
      '/guides/': [
        {
          text: 'Guides',
          items: [
            { text: 'Features', link: '/guides/features' },
            { text: 'Blocks', link: '/guides/blocks' },
            { text: 'Frontend', link: '/guides/frontend' },
            { text: 'Relationships', link: '/guides/relationships' },
            { text: 'Field Security', link: '/guides/field-security' },
            { text: 'AI Context', link: '/guides/ai-context' },
            { text: 'AI Assistants', link: '/guides/ai-assistants' },
            { text: 'WP-CLI', link: '/guides/wp-cli' },
            { text: 'WebMCP', link: '/guides/webmcp' },
            { text: 'Architecture', link: '/guides/architecture' },
            { text: 'Build & Setup', link: '/guides/build' },
          ],
        },
      ],
      '/mcp/': [
        {
          text: 'MCP/Abilities',
          items: [
            { text: 'Overview', link: '/mcp/index' },
            { text: 'Permissions', link: '/mcp/permissions' },
            { text: 'Runtime & Operations', link: '/mcp/runtime' },
            { text: 'Abilities Reference', link: '/mcp/abilities' },
            { text: 'Client Integration', link: '/mcp/clients' },
            { text: 'Saltus MCP Skill', link: '/mcp/skill' },
          ],
        },
      ],
      '/api/': [
        {
          text: 'API Reference',
          items: [
            { text: 'Overview', link: '/api/index' },
            { text: 'Config Reference', link: '/api/config-reference' },
          ],
        },
      ],
      '/PROJECT': PROJECT_SIDEBAR,
      '/CONTEXT': PROJECT_SIDEBAR,
      '/ROADMAP': PROJECT_SIDEBAR,
    },

    editLink: {
      pattern: 'https://github.com/SaltusDev/saltus-framework/edit/main/docs/:path',
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SaltusDev/saltus-framework' },
    ],

    footer: {
      message: 'GPL-3.0 License',
      copyright: 'Copyright Saltus Plugin Framework',
    },

    search: {
      provider: 'local',
    },
  },
})
