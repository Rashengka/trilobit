<?php

declare(strict_types=1);

namespace Trilobit\Tests\Combination;

use Dom\HTMLDocument;
use Nette\DI\Container;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Boot;

/**
 * `composer test` runs without Node and without a real `npm run build`, so it
 * must not need www/build to exist - that is what tests/Boot.php's manifest
 * fixture is for. A test asserting that claim only from a machine that
 * happens to have a real www/build lying around cannot tell "the fixture
 * wiring works" from "the fixture is not even being read, and ViteMapper
 * quietly fell through to the real file that was there anyway" - which is
 * exactly the failure this project's own CI hit once: green here, red in a
 * clean checkout.
 *
 * **The claim is asserted by making every read attributable, not by taking
 * www/build away.** A build directory is read twice - Nette\Assets\ViteMapper
 * reads the Vite manifest, and Trilobit\Core\Asset\VersionedViteMapper reads
 * the versions file beside it - so this build is pointed at a stand-in
 * directory of the suite's own, whose two files name things a real build never
 * produces. Every asset URL on every page then has to carry both marks. If
 * either read fell through to www/build the names would be the real ones and
 * the version marks would be missing, and the case fails.
 *
 * It used to be asserted the other way round: rename www/build out of the way,
 * render, and rename it back in tearDown. That worked and it left a landmine -
 * a run killed between the two (the suite ran out of memory once and PHP does
 * not run a tearDown on the way out) left the repository looking as though
 * somebody had deleted the build, and `npm run check:build` then reported a
 * missing build that was never missing. A test that has to survive its own
 * death to leave the repository as it found it is one that eventually does not,
 * so nothing here moves anything real any more.
 *
 * What this therefore no longer covers, and did not really cover before: code
 * that reads www/build by a path of its own rather than through the mapper.
 * There is none today - the mapper's base path is the only route - and a second
 * one would be a reason to bring an assertion about it back.
 */
#[CoversNothing]
final class NoRealBuildRequiredTest extends TestCase
{
    /**
     * What the fixtures name their built files, which no real build ever does:
     * bin/build-versions.mjs writes the names Vite writes, and Vite writes the
     * names in vite.config.ts.
     */
    private const string FROM_THE_FIXTURE = '-test-fixture.';

    public function testRenderingEveryPageReadsNothingOutOfTheRealBuildDirectory(): void
    {
        $modules = ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory());

        // The style guide is switched on here because it is the page carrying
        // the most components, and therefore the page most likely to be the one
        // that grows a dependency on something only a real build produces.
        $container = $this->builtAgainstTheStandIn($modules);

        $home = $this->rendered($container, 'Core:Front:Home');
        self::assertNotNull($home->querySelector('[data-testid="layout"]'));

        $styleguide = $this->rendered($container, 'Core:Styleguide:Overview');
        self::assertNotNull(
            $styleguide->querySelector('[data-styleguide-component]'),
            'the style guide did not render without a real www/build',
        );

        foreach (Build::SWITCHABLE as $module) {
            $document = $this->rendered($container, ucfirst($module) . ':Front:Status');
            self::assertNotNull(
                $document->querySelector('[data-testid="layout"]'),
                sprintf('%s did not render without a real www/build', $module),
            );
        }
    }

    /**
     * A build whose assets come out of tests/Fixtures and nowhere else.
     *
     * The manifest is already the fixture, from Trilobit\Tests\Boot; what is
     * added here is the directory the mapper treats as the build, which is
     * where the versions file is looked for. The URL is stated separately
     * because Nette\Bridges\AssetsDI\DIExtension derives one from the other
     * when it is not - so without this line, moving the directory would move
     * the addresses in the markup as well, and the pages would stop looking
     * like the pages production serves.
     */
    private function builtAgainstTheStandIn(ModuleList $modules): Container
    {
        return Boot::container($modules, styleguide: true, config: [
            'assets' => [
                'mapping' => [
                    'vite' => [
                        'path' => Bootstrap::rootDirectory() . '/tests/Fixtures/build',
                        'url' => 'build',
                    ],
                ],
            ],
        ]);
    }

    /**
     * The page, once it has been shown to be made of the fixture's assets
     * rather than of a build lying around on this machine.
     *
     * Both marks are asserted, because they come from different files: the name
     * is out of the manifest and the version is out of the versions file beside
     * it, and either could fall through to www/build on its own.
     */
    private function rendered(Container $container, string $presenter): HTMLDocument
    {
        $markup = Build::render($container, $presenter);

        $document = HTMLDocument::createFromString($markup, LIBXML_NOERROR);
        $assets = [
            ...iterator_to_array($document->querySelectorAll('script[src]')),
            ...iterator_to_array($document->querySelectorAll('link[rel="stylesheet"]')),
        ];
        self::assertNotSame([], $assets, sprintf('%s referred to no built asset at all', $presenter));

        foreach ($assets as $asset) {
            $address = $asset->getAttribute('src') ?? $asset->getAttribute('href') ?? '';

            self::assertStringContainsString(
                self::FROM_THE_FIXTURE,
                $address,
                sprintf('%s named %s, which is not a file any fixture produced', $presenter, $address),
            );
            self::assertMatchesRegularExpression(
                '#\?v=aaaaaaa\d$#',
                $address,
                sprintf('%s named %s, whose version did not come from the fixture either', $presenter, $address),
            );
        }

        return $document;
    }
}
