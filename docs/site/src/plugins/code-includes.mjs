import { existsSync, readFileSync } from 'node:fs';
import * as path from 'node:path';
import { DOCS_ROOT } from './doc-links.mjs';

export const EXAMPLES_ROOT = path.join(DOCS_ROOT, 'examples') + path.sep;

const FILE_META = /(?:^|\s)file=(\S+)/;

/**
 * Resolves the `file=` of a code fence's meta against the markdown file's directory.
 *
 * Returns `undefined` for a fence without one, `{ code, title }` for a file under
 * docs/examples/, or `{ problem }` saying why it cannot be included.
 */
export function resolveCodeInclude(meta, fromDirectory) {
    const target = FILE_META.exec(meta ?? '')?.[1];

    if (!target) {
        return undefined;
    }

    const resolved = path.resolve(fromDirectory, target);

    if (!resolved.startsWith(EXAMPLES_ROOT)) {
        return { problem: `${target} is outside docs/examples/` };
    }

    if (!existsSync(resolved)) {
        return { problem: `${target} does not exist` };
    }

    return {
        code: readFileSync(resolved, 'utf8').trimEnd(),
        title: path.relative(EXAMPLES_ROOT, resolved).split(path.sep).join('/'),
    };
}
