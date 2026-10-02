import { viteBundler } from '@vuepress/bundler-vite'
import { searchPlugin } from '@vuepress/plugin-search'
import { defaultTheme } from '@vuepress/theme-default'
import { defineUserConfig } from 'vuepress'

// 部署到 GitHub Pages 时站点位于 https://<user>.github.io/<repo>/，
// 所以 base 要带仓库名。CI 里由 workflow 注入 DOCS_BASE；本地默认 '/'。
const base = process.env.DOCS_BASE || '/'

/** 英文站（根路径）的导航与侧边栏。 */
const enNavbar = [
  { text: 'Guide', link: '/guide/' },
  { text: 'Widgets', link: '/widgets/' },
  { text: 'Advanced', link: '/advanced/' },
  { text: 'Reference', link: '/reference/' },
  { text: 'FAQ', link: '/faq.html' },
]

const enSidebar = {
  '/guide/': [
    {
      text: 'Guide',
      children: [
        '/guide/README.md',
        '/guide/installation.md',
        '/guide/qt-setup.md',
        '/guide/quickstart.md',
        '/guide/project-structure.md',
        '/guide/architecture.md',
        '/guide/state-and-view.md',
      ],
    },
    {
      text: 'The UI',
      children: [
        '/guide/events.md',
        '/guide/properties.md',
        '/guide/layout.md',
        '/guide/dialogs.md',
        '/guide/menus-tray-timers.md',
        '/guide/patching.md',
      ],
    },
  ],
  '/widgets/': [
    {
      text: 'Widget Catalog',
      children: [
        '/widgets/README.md',
        '/widgets/containers.md',
        '/widgets/inputs.md',
        '/widgets/data.md',
        '/widgets/display.md',
      ],
    },
  ],
  '/advanced/': [
    {
      text: 'Advanced',
      children: [
        '/advanced/README.md',
        '/advanced/aot-notes.md',
        '/advanced/bridge.md',
        '/advanced/diff-engine.md',
        '/advanced/headless.md',
      ],
    },
  ],
  '/reference/': [
    {
      text: 'Reference',
      children: [
        '/reference/README.md',
        '/reference/cli.md',
        '/reference/api.md',
        '/reference/packaging.md',
        '/reference/platforms.md',
      ],
    },
  ],
  '/faq.html': [
    {
      text: 'FAQ',
      children: ['/faq.md'],
    },
  ],
}

/** 中文站（/zh/）的导航与侧边栏。 */
const zhNavbar = [
  { text: '指南', link: '/zh/guide/' },
  { text: '控件', link: '/zh/widgets/' },
  { text: '深入', link: '/zh/advanced/' },
  { text: '参考', link: '/zh/reference/' },
  { text: 'FAQ', link: '/zh/faq.html' },
]

const zhSidebar = {
  '/zh/guide/': [
    {
      text: '指南',
      children: [
        '/zh/guide/README.md',
        '/zh/guide/installation.md',
        '/zh/guide/qt-setup.md',
        '/zh/guide/quickstart.md',
        '/zh/guide/project-structure.md',
        '/zh/guide/architecture.md',
        '/zh/guide/state-and-view.md',
      ],
    },
    {
      text: '界面',
      children: [
        '/zh/guide/events.md',
        '/zh/guide/properties.md',
        '/zh/guide/layout.md',
        '/zh/guide/dialogs.md',
        '/zh/guide/menus-tray-timers.md',
        '/zh/guide/patching.md',
      ],
    },
  ],
  '/zh/widgets/': [
    {
      text: '控件目录',
      children: [
        '/zh/widgets/README.md',
        '/zh/widgets/containers.md',
        '/zh/widgets/inputs.md',
        '/zh/widgets/data.md',
        '/zh/widgets/display.md',
      ],
    },
  ],
  '/zh/advanced/': [
    {
      text: '深入',
      children: [
        '/zh/advanced/README.md',
        '/zh/advanced/aot-notes.md',
        '/zh/advanced/bridge.md',
        '/zh/advanced/diff-engine.md',
        '/zh/advanced/headless.md',
      ],
    },
  ],
  '/zh/reference/': [
    {
      text: '参考',
      children: [
        '/zh/reference/README.md',
        '/zh/reference/cli.md',
        '/zh/reference/api.md',
        '/zh/reference/packaging.md',
        '/zh/reference/platforms.md',
      ],
    },
  ],
  '/zh/faq.html': [
    {
      text: '常见问题',
      children: ['/zh/faq.md'],
    },
  ],
}

export default defineUserConfig({
  base,
  // 站点标题与描述是英文的：根路径是默认语言，也是搜索引擎看到的那份。
  title: 'TypePHP\\Qt',
  description: 'Build native desktop apps with TypePHP (AOT) + Qt 6',

  head: [
    ['meta', { name: 'theme-color', content: '#1d4ed8' }],
    ['meta', { name: 'author', content: 'yangweijie' }],
  ],

  bundler: viteBundler(),

  // 多语言站点：每种语言的 head 需要各自的 lang 属性。
  locales: {
    '/': {
      lang: 'en-US',
      title: 'TypePHP\\Qt',
      description: 'Build native desktop apps with TypePHP (AOT) + Qt 6',
    },
    '/zh/': {
      lang: 'zh-CN',
      title: 'TypePHP\\Qt',
      description: '用 TypePHP (AOT) + Qt 6 开发原生桌面应用',
    },
  },

  plugins: [
    searchPlugin({
      // 搜索索引按语言分开，否则中英混排会互相干扰。
      // 中文没有词间空格，默认的英文分词查不到东西 —— 所以各自给占位提示。
      locales: {
        '/': { placeholder: 'Search docs' },
        '/zh/': { placeholder: '搜索文档' },
      },
      maxSuggestions: 10,
      isSearchable: (page) => !page.path.endsWith('/404.html'),
    }),
  ],

  theme: defaultTheme({
    logo: null,
    repo: 'yangweijie/typephp-qt',
    docsDir: 'docs/src',
    contributors: false,

    // theme-default 内置了 links-check 插件，这里只调它的行为：
    // 死链直接让构建失败，而不是打印警告后照常产出。
    themePlugins: {
      linksCheck: {
        build: 'error',
        dev: true,
      },
    },

    locales: {
      '/': {
        selectLanguageName: 'English',
        selectLanguageText: 'Languages',
        editLink: true,
        editLinkText: 'Edit this page on GitHub',
        lastUpdated: true,
        lastUpdatedText: 'Last updated',
        navbar: enNavbar,
        sidebar: enSidebar,
      },
      '/zh/': {
        selectLanguageName: '简体中文',
        selectLanguageText: '选择语言',
        editLink: true,
        editLinkText: '在 GitHub 上编辑此页',
        lastUpdated: true,
        lastUpdatedText: '最后更新',
        navbar: zhNavbar,
        sidebar: zhSidebar,
      },
    },
  }),
})
