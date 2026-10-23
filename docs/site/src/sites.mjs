import { readdirSync, readFileSync } from 'node:fs';
import * as path from 'node:path';
import { contentRootOf, idForRelativePath } from './plugins/doc-links.mjs';

export const SITE_URL = 'https://stewart-php.github.io';
export const EDIT_BASE = 'https://github.com/stewart-php/stewart/edit/main/docs/site/';
const ORDER = /^sidebar:\s*\n\s+order:\s*(-?\d+)\s*$/m;

export const SITES = {
    user: {
        tree: 'user',
        base: '/docs',
        port: 4321,
        title: 'Stewart',
        description: 'Write Home Assistant automations in PHP.',
        // Pages that moved, from the old path to the new one, both below the base.
        redirects: {
            '/guides/calling-services/': '/guides/calling-actions/',
        },
        sidebar: (site) => [
            { label: 'Get started', items: directoryItems(site, 'start') },
            { label: 'Guides', items: directoryItems(site, 'guides') },
            { label: 'Recipes', items: directoryItems(site, 'recipes') },
            { label: 'Deploy', items: directoryItems(site, 'deploy') },
            { label: 'Reference', items: directoryItems(site, 'reference') },
            { label: 'Help', items: directoryItems(site, 'help') },
        ],
    },
    dev: {
        tree: 'dev',
        base: '/docs-dev',
        port: 4322,
        title: 'Stewart — Developers',
        description: 'How Stewart is built: architecture, code walkthrough and protocols.',
        sidebar: () => [
            {
                label: 'Contributing',
                items: ['contributing', 'conventions', 'testing', 'releasing', 'changelog'],
            },
            {
                label: 'Internals',
                items: ['architecture', 'protocol', 'control-protocol', 'component-protocol'],
            },
            {
                label: 'Code walkthrough',
                items: ['walkthrough/index', 'walkthrough/console', 'walkthrough/broker', 'walkthrough/worker', 'walkthrough/client'],
            },
        ],
    },
};

export function selectSite() {
    const name = process.env.DOCS_SITE ?? 'user';
    const site = SITES[name];

    if (!site) {
        throw new Error(`Unknown DOCS_SITE ${JSON.stringify(name)}; expected one of ${Object.keys(SITES).join(', ')}.`);
    }

    const archive = process.env.DOCS_ARCHIVE ?? '';

    if (process.env.DOCS_PREVIEW !== '1' && archive === '') {
        return site;
    }

    if (name !== 'user') {
        throw new Error('DOCS_PREVIEW and DOCS_ARCHIVE build only the user site.');
    }

    if (archive !== '') {
        if (!/^\d+$/.test(archive)) {
            throw new Error(`DOCS_ARCHIVE ${JSON.stringify(archive)} is not a major version, such as 1.`);
        }

        return { ...site, base: `${site.base}/${archive}.x`, archive };
    }

    return { ...site, base: `${site.base}/next`, preview: true };
}

export function listOtherTrees(site) {
    return Object.values(SITES)
        .map(({ tree }) => tree)
        .filter((tree) => tree !== site.tree);
}

function sidebarOrder(file) {
    return Number(ORDER.exec(readFileSync(file, 'utf8'))?.[1] ?? Number.MAX_SAFE_INTEGER);
}

// Starlight's `autogenerate` matches paths inside its own collection directory, which this project does not use.
function directoryItems(site, directory) {
    const absolute = path.join(contentRootOf(site), directory);

    return readdirSync(absolute)
        .filter((name) => /\.mdx?$/i.test(name))
        .map((name) => ({
            slug: idForRelativePath(path.join(directory, name)),
            order: sidebarOrder(path.join(absolute, name)),
            name,
        }))
        .sort((a, b) => a.order - b.order || a.name.localeCompare(b.name))
        .map(({ slug }) => ({ slug }));
}
