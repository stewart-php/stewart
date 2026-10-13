import * as path from 'node:path';
import { fileURLToPath } from 'node:url';
import { REPO_ROOT, resolveDocLink } from './doc-links.mjs';

/**
 * Rewrites file-relative links between markdown sources into site routes, so
 * one link works both on the site and when the file is read on GitHub. Targets
 * outside the content root resolve to the repository on GitHub.
 *
 * scripts/check-links.mjs is what fails the build on a broken link; the throw
 * here surfaces one during `astro dev`, where that check does not run.
 */
export default function mdastRelativeDocLinks(site, otherTrees) {
    return ({ fileURL }) => {
        if (!fileURL) {
            return;
        }

        const sourcePath = fileURLToPath(fileURL);
        const fromDirectory = path.dirname(sourcePath);

        return {
            name: 'stewart-relative-doc-links',
            link(node) {
                const resolved = resolveDocLink(site, otherTrees, node.url, fromDirectory);

                if (!resolved) {
                    return;
                }

                if (resolved.crossed) {
                    throw new Error(
                        `Documentation link ${JSON.stringify(node.url)} in ` +
                            `${path.relative(REPO_ROOT, sourcePath)} points into docs/${resolved.crossed}/; ` +
                            'link the other site by its absolute URL.',
                    );
                }

                if (resolved.missing) {
                    throw new Error(
                        `Broken documentation link ${JSON.stringify(node.url)} in ` +
                            `${path.relative(REPO_ROOT, sourcePath)}: ` +
                            `${path.relative(REPO_ROOT, resolved.missing)} does not exist.`,
                    );
                }

                return {
                    type: 'link',
                    url: resolved.url,
                    title: node.title ?? null,
                    children: node.children,
                };
            },
        };
    };
}
