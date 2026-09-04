/**
 * Crm's own bundle, loaded only on pages under Crm:Front - see
 * src/Crm/Presentation/Front/templates/@layout.latte. Nothing here yet
 * beyond the marker below; a build with Crm switched off never resolves
 * this file at all, because vite.config.ts only lists it as an entry point
 * when var/build/modules.json says Crm is enabled.
 */
export {};
