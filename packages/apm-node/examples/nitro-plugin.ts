// server/plugins/falak.ts (Nuxt 3/4 or standalone Nitro)
import falak from '@othmanhaba/apm-node/nitro';

// defineNitroPlugin is auto-imported by Nitro.
declare const defineNitroPlugin: <T>(plugin: T) => T;

export default defineNitroPlugin(falak);
