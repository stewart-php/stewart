import { defineConfig } from 'astro/config';
import { satteri } from '@astrojs/markdown-satteri';
import starlight from '@astrojs/starlight';
import starlightThemeRapide from 'starlight-theme-rapide';
import mdastIncludeCode from './src/plugins/mdast-include-code.mjs';
import mdastRelativeDocLinks from './src/plugins/mdast-relative-doc-links.mjs';
import { EDIT_BASE, SITE_URL, listOtherTrees, selectSite } from './src/sites.mjs';

const site = selectSite();

export default defineConfig({
    site: SITE_URL,
    base: site.base,
    outDir: `./dist${site.base}`,
    // Separate caches keep one site's content store out of the other's build.
    cacheDir: `./node_modules/.astro/${site.tree}`,
    server: { port: site.port },
    // Astro prefixes the base to where a redirect starts, but not to where it leads.
    redirects: Object.fromEntries(Object.entries(site.redirects ?? {}).map(([from, to]) => [from, `${site.base}${to}`])),
    markdown: {
        processor: satteri({ mdastPlugins: [mdastIncludeCode(), mdastRelativeDocLinks(site, listOtherTrees(site))] }),
    },
    integrations: [
        starlight({
            plugins: [starlightThemeRapide()],
            // Content lives outside src/content/docs/, which is the only tree
            // Starlight's Markdown transforms touch by default.
            markdown: { processedDirs: [`../${site.tree}`] },
            title: site.title,
            description: site.description,
            editLink: { baseUrl: EDIT_BASE },
            social: [
                {
                    icon: 'github',
                    label: 'GitHub',
                    href: 'https://github.com/stewart-php',
                },
            ],
            sidebar: site.sidebar(site),
            head: site.preview ? [{ tag: 'meta', attrs: { name: 'robots', content: 'noindex' } }] : [],
            routeMiddleware: site.preview ? ['./src/preview-banner.mjs'] : [],
        }),
    ],
    // The content trees sit above this project, in docs/<tree>/.
    vite: { server: { fs: { allow: ['..'] } } },
});
