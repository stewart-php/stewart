import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { docsSchema } from '@astrojs/starlight/schema';
import { idForRelativePath } from './plugins/doc-links.mjs';
import { selectSite } from './sites.mjs';

// Content lives in docs/<tree>/, outside this project, so Starlight's
// docsLoader() (hardcoded to src/content/docs/) cannot be used.
export const collections = {
    docs: defineCollection({
        loader: glob({
            pattern: '**/[^_]*.{md,mdx}',
            base: `../${selectSite().tree}`,
            generateId: ({ entry }) => idForRelativePath(entry),
        }),
        schema: docsSchema(),
    }),
};
