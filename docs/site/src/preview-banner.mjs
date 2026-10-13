import { defineRouteMiddleware } from '@astrojs/starlight/route-data';

export const onRequest = defineRouteMiddleware((context) => {
    context.locals.starlightRoute.entry.data.banner = {
        content: 'These pages describe the unreleased version on <code>main</code>. <a href="/docs/">Read the docs for the latest release</a>.',
    };
});
