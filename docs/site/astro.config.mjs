import { defineConfig } from 'astro/config';
import { satteri } from '@astrojs/markdown-satteri';
import starlight from '@astrojs/starlight';
import starlightThemeRapide from 'starlight-theme-rapide';
import mdastRelativeDocLinks from './src/plugins/mdast-relative-doc-links.mjs';
import { directoryItems } from './src/sidebar.mjs';

export default defineConfig({
    markdown: {
        processor: satteri({ mdastPlugins: [mdastRelativeDocLinks()] }),
    },
    integrations: [
        starlight({
            plugins: [starlightThemeRapide()],
            // Content lives outside src/content/docs/, which is the only tree
            // Starlight's Markdown transforms touch by default.
            markdown: { processedDirs: ['../user', '../dev'] },
            title: 'Stewart',
            description: 'Write Home Assistant automations in PHP.',
            social: [
                {
                    icon: 'github',
                    label: 'GitHub',
                    href: 'https://github.com/stewart-php',
                },
            ],
            sidebar: [
                { label: 'User guide', items: directoryItems('user/guides') },
                {
                    label: 'Developer guides',
                    items: [
                        {
                            label: 'Internals',
                            items: ['dev/architecture', 'dev/walkthrough', 'dev/protocol', 'dev/component-protocol'],
                        },
                    ],
                },
            ],
        }),
    ],
    // The content trees sit above this project, in docs/user/ and docs/dev/.
    vite: { server: { fs: { allow: ['..'] } } },
});
