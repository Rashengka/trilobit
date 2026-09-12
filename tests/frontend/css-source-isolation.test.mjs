// Tailwind's automatic source detection reads every word of every file it
// finds under the project root - see assets/app.css's own comment about
// tests/ and vite-plugins/ having done exactly that. www/build is committed
// (see the README), so it is not excluded by the same .gitignore-based rule
// that keeps node_modules/ and var/ out of the scan - and it holds bundled
// third-party code such as Tom Select, whose own source carries words like
// "blur" and "resize" that also happen to be Tailwind utility names. Without
// an explicit `@source not`, a build's output becomes an input to the next
// build: an empty www/build produces a smaller app.css than a populated one,
// for the same sources and the same templates.
//
// This is tested against an isolated copy of the project rather than the
// real www/build, for the same reason manifest.test.mjs never writes there:
// a developer or another process may be relying on it, and this test needs
// to vary its *content*, not just read it.
//
// Run with: npm run test:frontend

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import {
    cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));

/** A minimal, valid module list - which modules are enabled does not matter for this test. */
const MODULES = {
    modules: [
        { name: 'cms', namespace: 'Trilobit\\Cms', directory: 'src/Cms' },
        { name: 'crm', namespace: 'Trilobit\\Crm', directory: 'src/Crm' },
        { name: 'shop', namespace: 'Trilobit\\Shop', directory: 'src/Shop' },
    ],
};

/**
 * A standalone copy of everything a build reads, isolated from the real
 * repository so that this test can vary www/build's content - the thing
 * under test - without ever touching the real one.
 */
function isolatedProject() {
    const dir = mkdtempSync(join(tmpdir(), 'trilobit-css-isolation-'));

    for (const entry of ['assets', 'src', 'vite-plugins', 'package.json', 'tsconfig.json', 'vite.config.ts']) {
        cpSync(join(ROOT, entry), join(dir, entry), { recursive: true });
    }

    // Symlinked rather than copied: it is large, and nothing here modifies it.
    symlinkSync(join(ROOT, 'node_modules'), join(dir, 'node_modules'));

    const modulesFile = join(dir, 'modules.json');
    writeFileSync(modulesFile, JSON.stringify(MODULES));

    // Normally written by `bin/trilobit app:warmup`; assets/app.css imports it
    // by a fixed path, so a build needs it regardless of TRILOBIT_MODULES_FILE.
    mkdirSync(join(dir, 'var', 'build'), { recursive: true });
    writeFileSync(
        join(dir, 'var', 'build', 'sources.css'),
        ['Core', ...MODULES.modules.map((module) => module.name[0].toUpperCase() + module.name.slice(1))]
            .map((name) => `@source "../../src/${name}";`)
            .join('\n'),
    );

    return { dir, modulesFile };
}

/** Runs a real `vite build` inside the isolated copy and returns the app.css it produced. */
function builtCss({ dir, modulesFile }) {
    const outDir = mkdtempSync(join(tmpdir(), 'trilobit-css-isolation-out-'));

    execFileSync(join(ROOT, 'node_modules', '.bin', 'vite'), ['build', '--outDir', outDir], {
        cwd: dir,
        env: { ...process.env, TRILOBIT_MODULES_FILE: modulesFile },
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    const css = readFileSync(join(outDir, 'app.css'), 'utf8');
    rmSync(outDir, { recursive: true, force: true });

    return css;
}

test('app.css does not depend on what was already sitting in www/build', () => {
    const project = isolatedProject();

    try {
        cpSync(join(ROOT, 'www', 'build'), join(project.dir, 'www', 'build'), { recursive: true });
        const withPriorBuild = builtCss(project);

        rmSync(join(project.dir, 'www', 'build'), { recursive: true, force: true });
        mkdirSync(join(project.dir, 'www', 'build'), { recursive: true });
        const withEmptyBuild = builtCss(project);

        assert.equal(
            withEmptyBuild,
            withPriorBuild,
            'app.css changed depending on the previous contents of www/build - '
            + 'Tailwind is scanning the committed build output as a class-name source. '
            + 'Exclude it in assets/app.css: @source not "../www/build";',
        );
    } finally {
        rmSync(project.dir, { recursive: true, force: true });
    }
});
