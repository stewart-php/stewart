import { readFileSync } from 'node:fs';
import { defineCollection } from 'astro:content';
import { glob, type Loader } from 'astro/loaders';
import { docsSchema } from '@astrojs/starlight/schema';
import { idForRelativePath } from './plugins/doc-links.mjs';
import { selectSite } from './sites.mjs';

const site = selectSite();
const CHANGELOG = new URL('../../../CHANGELOG.md', import.meta.url);
const CHANGELOG_EDIT_URL = 'https://github.com/stewart-php/stewart/edit/main/CHANGELOG.md';

// The repository's CHANGELOG.md lives outside docs/dev/ and has no frontmatter, so the dev site adds it by hand.
function withChangelog(loader: Loader): Loader {
    return {
        name: `${loader.name}-with-changelog`,
        load: async (context) => {
            await loader.load(context);

            const body = readFileSync(CHANGELOG, 'utf8').replace(/^# .*\n/, '');
            const data = await context.parseData({
                id: 'changelog',
                data: { title: 'Changelog', description: 'Every change of every Stewart release.', editUrl: CHANGELOG_EDIT_URL },
            });

            context.store.set({ id: 'changelog', data, body, rendered: await context.renderMarkdown(body), digest: context.generateDigest(body) });
        },
    };
}

// Content lives in docs/<tree>/, outside this project, so Starlight's
// docsLoader() (hardcoded to src/content/docs/) cannot be used.
const pages = glob({
    pattern: '**/[^_]*.{md,mdx}',
    base: `../${site.tree}`,
    generateId: ({ entry }) => idForRelativePath(entry),
});

export const collections = {
    docs: defineCollection({
        loader: site.tree === 'dev' ? withChangelog(pages) : pages,
        schema: docsSchema(),
    }),
};
