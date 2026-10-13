import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { docsSchema } from '@astrojs/starlight/schema';
import { idForRelativePath } from './plugins/doc-links.mjs';

// Content lives in docs/user/ and docs/dev/, outside this project, so Starlight's
// docsLoader() (hardcoded to src/content/docs/) cannot be used.
export const collections = {
    docs: defineCollection({
        loader: glob({
            pattern: '{user,dev}/**/[^_]*.{md,mdx}',
            base: '..',
            generateId: ({ entry }) => idForRelativePath(entry),
        }),
        schema: docsSchema(),
    }),
};
