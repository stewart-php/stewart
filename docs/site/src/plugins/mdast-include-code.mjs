import * as path from 'node:path';
import { fileURLToPath } from 'node:url';
import { REPO_ROOT } from './doc-links.mjs';
import { resolveCodeInclude } from './code-includes.mjs';

/**
 * Fills a code fence whose meta names `file=<path>` with that file from
 * docs/examples/, where PHPStan and the tests check it, and titles it with
 * the file's path inside a project (`apps/DoorAlarm.php`).
 */
export default function mdastIncludeCode() {
    return ({ fileURL }) => {
        if (!fileURL) {
            return;
        }

        const sourcePath = fileURLToPath(fileURL);

        return {
            name: 'stewart-include-code',
            code(node) {
                const include = resolveCodeInclude(node.meta, path.dirname(sourcePath));

                if (!include) {
                    return;
                }

                if (include.problem) {
                    throw new Error(`Code include in ${path.relative(REPO_ROOT, sourcePath)}: ${include.problem}.`);
                }

                return {
                    type: 'code',
                    lang: node.lang,
                    meta: `title="${include.title}"`,
                    value: include.code,
                };
            },
        };
    };
}
