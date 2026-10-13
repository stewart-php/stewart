import { existsSync } from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

export const CONTENT_ROOT = fileURLToPath(new URL('../../../', import.meta.url));
export const REPO_ROOT = path.resolve(CONTENT_ROOT, '..');
export const CONTENT_TREES = ['user', 'dev'];

const REPO_BLOB = 'https://github.com/stewart-php/stewart/blob/main';
const ABSOLUTE = /^([a-z][a-z0-9+.-]*:|\/\/|#|\/)/i;
const MARKDOWN = /\.mdx?$/i;

/** Entry id for a path relative to CONTENT_ROOT; user pages sit at the site root. */
export function idForRelativePath(relativePath) {
    return relativePath
        .split(path.sep)
        .join('/')
        .replace(/^user\//, '')
        .replace(/\.[^.]+$/, '')
        .replace(/(^|\/)README$/i, '$1index');
}

function entryId(absolutePath) {
    return idForRelativePath(path.relative(CONTENT_ROOT, absolutePath));
}

function idToRoute(id) {
    const route = id.replace(/(^|\/)index$/, '');

    return route ? `/${route}/` : '/';
}

/** The file a directory link resolves to, or undefined when there is none. */
function directoryIndex(directory) {
    return ['index.md', 'index.mdx', 'README.md']
        .map((name) => path.join(directory, name))
        .find((candidate) => existsSync(candidate));
}

/**
 * Resolves a file-relative markdown link against the documentation tree.
 *
 * Returns `undefined` when the link is not ours to touch, `{ url }` for a link
 * that resolves, or `{ missing }` naming the path that does not exist.
 */
export function resolveDocLink(url, fromDirectory) {
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

    if (resolved.startsWith(CONTENT_ROOT)) {
        const file = isDirectory ? directoryIndex(resolved) : resolved;

        if (!file || !existsSync(file)) {
            return { missing: resolved };
        }

        return { url: idToRoute(entryId(file)) + suffix };
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
