import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Saltus Framework',
  description: 'WordPress plugin development framework for Custom Post Types',
  lang: 'en-US',
  base: '/',

  ignoreDeadLinks: [
    /^\/roadmap/,
    /^\/current/,
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
        text: 'Discovery',
        items: [
          { text: 'WebMCP', link: '/discovery/webmcp' },
          { text: 'Declarative Forms', link: '/discovery/webmcp-declarative-forms' },
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
            { text: 'Abilities Reference', link: '/mcp/abilities' },
            { text: 'Client Integration', link: '/mcp/clients' },
            { text: 'Saltus MCP Skill', link: '/mcp/skill' },
          ],
        },
      ],
      '/discovery/': [
        {
          text: 'Discovery',
          items: [
            { text: 'WebMCP', link: '/discovery/webmcp' },
            { text: 'Declarative Forms', link: '/discovery/webmcp-declarative-forms' },
          ],
        },
      ],
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
