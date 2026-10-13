/**
 * Fails when a markdown link in docs/user/ or docs/dev/ points at a file that is not there.
 * Runs before `astro build`, because a link the content loader cannot resolve is
 * only logged during the build, not fatal.
 */
import { readdirSync, readFileSync } from 'node:fs';
import * as path from 'node:path';
import { CONTENT_ROOT, CONTENT_TREES, REPO_ROOT, resolveDocLink } from '../src/plugins/doc-links.mjs';

const FENCE = /^```[\s\S]*?^```$/gm;
const INLINE_CODE = /`[^`\n]*`/g;
const LINK = /\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g;

function markdownFiles(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            return markdownFiles(full);
        }

        return /\.mdx?$/i.test(entry.name) ? [full] : [];
    });
}

const broken = [];

for (const file of CONTENT_TREES.flatMap((tree) => markdownFiles(path.join(CONTENT_ROOT, tree)))) {
    const source = readFileSync(file, 'utf8').replace(FENCE, '').replace(INLINE_CODE, '');

    for (const [, url] of source.matchAll(LINK)) {
        const resolved = resolveDocLink(url, path.dirname(file));

        if (resolved?.missing) {
            broken.push({
                file: path.relative(REPO_ROOT, file),
                url,
                missing: path.relative(REPO_ROOT, resolved.missing),
            });
        }
    }
}

if (broken.length > 0) {
    for (const { file, url, missing } of broken) {
        console.error(`${file}: ${url} -> ${missing} does not exist`);
    }

    console.error(`\n${broken.length} broken documentation link(s).`);
    process.exit(1);
}

console.log('Documentation links resolve.');
