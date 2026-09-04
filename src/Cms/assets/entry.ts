/**
 * Cms's own bundle, loaded only on pages under Cms:Front - see
 * src/Cms/Presentation/Front/templates/@layout.latte. Nothing here yet
 * beyond the marker below; a build with Cms switched off never resolves
 * this file at all, because vite.config.ts only lists it as an entry point
 * when var/build/modules.json says Cms is enabled.
 */
export {};
