/**
 * Tells Prism, before it loads, that it will be driven by hand - see ./prism.
 *
 * A module of its own because imports run in the order they are written and
 * before the body of the module importing them: the flag has to be set by a
 * module that runs before Prism's core does, and a line in prism.ts itself
 * would run after it.
 */
(window as unknown as { Prism?: { manual?: boolean } }).Prism = { manual: true };

export {};
