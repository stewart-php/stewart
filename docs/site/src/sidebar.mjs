import { readdirSync, readFileSync } from 'node:fs';
import * as path from 'node:path';
import { CONTENT_ROOT, idForRelativePath } from './plugins/doc-links.mjs';
const ORDER = /^sidebar:\s*\n\s+order:\s*(-?\d+)\s*$/m;

function sidebarOrder(file) {
    return Number(ORDER.exec(readFileSync(file, 'utf8'))?.[1] ?? Number.MAX_SAFE_INTEGER);
}

/**
 * Sidebar entries for every page in a content directory, ordered by the
 * `sidebar.order` in their frontmatter. Starlight's `autogenerate` matches on
 * paths inside its own collection directory, which this project does not use.
 */
export function directoryItems(directory) {
    const absolute = path.join(CONTENT_ROOT, directory);

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
