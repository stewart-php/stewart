import { defineRouteMiddleware } from '@astrojs/starlight/route-data';

const major = process.env.DOCS_ARCHIVE;

export const onRequest = defineRouteMiddleware((context) => {
    context.locals.starlightRoute.entry.data.banner = {
        content: `These pages describe Stewart ${major}.x. <a href="/docs/">Read the docs for the latest release</a>, and <a href="/docs/help/upgrading/">how to upgrade</a>.`,
    };
});
