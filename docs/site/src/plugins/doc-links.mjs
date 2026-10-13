import { existsSync } from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

export const DOCS_ROOT = fileURLToPath(new URL('../../../', import.meta.url));
export const REPO_ROOT = path.resolve(DOCS_ROOT, '..');

const REPO_BLOB = 'https://github.com/stewart-php/stewart/blob/main';
const ABSOLUTE = /^([a-z][a-z0-9+.-]*:|\/\/|#|\/)/i;
const MARKDOWN = /\.mdx?$/i;

export function contentRootOf(site) {
    return path.join(DOCS_ROOT, site.tree) + path.sep;
}

/** Entry id for a path relative to the site's content root, matching generateId() in content.config.ts. */
export function idForRelativePath(relativePath) {
    return relativePath
        .split(path.sep)
        .join('/')
        .replace(/\.[^.]+$/, '')
        .replace(/(^|\/)README$/i, '$1index');
}

function idToRoute(site, id) {
    const route = id.replace(/(^|\/)index$/, '');

    return route ? `${site.base}/${route}/` : `${site.base}/`;
}

/** The file a directory link resolves to, or undefined when there is none. */
function directoryIndex(directory) {
    return ['index.md', 'index.mdx', 'README.md']
        .map((name) => path.join(directory, name))
        .find((candidate) => existsSync(candidate));
}

function treeOf(absolutePath) {
    const relative = path.relative(DOCS_ROOT, absolutePath);

    return relative.startsWith('..') ? undefined : relative.split(path.sep)[0];
}

/**
 * Resolves a file-relative markdown link against one site's content tree.
 *
 * Returns `undefined` when the link is not ours to touch, `{ url }` for a link
 * that resolves, `{ missing }` naming the path that does not exist, or
 * `{ crossed }` naming the other site's tree the link points into.
 */
export function resolveDocLink(site, otherTrees, url, fromDirectory) {
    if (!url || ABSOLUTE.test(url)) {
        return undefined;
    }

    const [target, ...rest] = url.split(/(?=[#?])/);
    const suffix = rest.join('');
    const isDirectory = target.endsWith('/');

    if (!target || (!MARKDOWN.test(target) && !isDirectory)) {
        return undefined;
    }

    const resolved = path.resolve(fromDirectory, target);
    const contentRoot = contentRootOf(site);

    if (resolved.startsWith(contentRoot)) {
        const file = isDirectory ? directoryIndex(resolved) : resolved;

        if (!file || !existsSync(file)) {
            return { missing: resolved };
        }

        return { url: idToRoute(site, idForRelativePath(path.relative(contentRoot, file))) + suffix };
    }

    const tree = treeOf(resolved);

    if (tree && otherTrees.includes(tree)) {
        return { crossed: tree };
    }

    if (resolved.startsWith(REPO_ROOT)) {
        if (!existsSync(resolved)) {
            return { missing: resolved };
        }

        const relative = path.relative(REPO_ROOT, resolved).split(path.sep).join('/');

        return { url: `${REPO_BLOB}/${relative}${suffix}` };
    }

    return undefined;
}
