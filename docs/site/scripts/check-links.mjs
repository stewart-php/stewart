/**
 * Fails when a markdown link in a site's tree points at a file that is not there
 * or into the other site's tree. Runs before `astro build`, because a link the
 * content loader cannot resolve is only logged during the build, not fatal.
 */
import { readdirSync, readFileSync } from 'node:fs';
import * as path from 'node:path';
import { DOCS_ROOT, REPO_ROOT, contentRootOf, resolveDocLink } from '../src/plugins/doc-links.mjs';
import { SITES, listOtherTrees } from '../src/sites.mjs';

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

const problems = [];

for (const site of Object.values(SITES)) {
    const otherTrees = listOtherTrees(site);

    for (const file of markdownFiles(contentRootOf(site))) {
        const source = readFileSync(file, 'utf8').replace(FENCE, '').replace(INLINE_CODE, '');
        const relativeFile = path.relative(REPO_ROOT, file);

        for (const [, url] of source.matchAll(LINK)) {
            const resolved = resolveDocLink(site, otherTrees, url, path.dirname(file));

            if (resolved?.missing) {
                problems.push(`${relativeFile}: ${url} -> ${path.relative(REPO_ROOT, resolved.missing)} does not exist`);
            }

            if (resolved?.crossed) {
                problems.push(
                    `${relativeFile}: ${url} points into ${path.relative(REPO_ROOT, path.join(DOCS_ROOT, resolved.crossed))}/; use the other site's absolute URL`,
                );
            }
        }
    }
}

if (problems.length > 0) {
    problems.forEach((problem) => console.error(problem));
    console.error(`\n${problems.length} documentation link problem(s).`);
    process.exit(1);
}

console.log('Documentation links resolve.');
