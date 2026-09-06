<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Presentation\Design\DesignSystem;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Combination\Build;

/**
 * Every preference this build has, and every answer it accepts, is something
 * somebody can actually pick.
 *
 * The catalogue is a list in code and the controls are written by hand in the
 * style guide's template, which is deliberate - a control has a label and an
 * order, and neither is derivable from a name. What is not acceptable is the
 * pair drifting apart, and it drifts silently in both directions: a value with
 * no control is a mode nobody can reach, and a control for a value the
 * catalogue does not have posts a choice the server answers with 400 and the
 * page carries on looking right, because the switch had already changed it.
 *
 * It is the same claim
 * Trilobit\Tests\Template\StyleguideShowsEveryContentGroupTest makes about the
 * native elements, made against the rendered page for the same reason: a control
 * inside a section that fails to render is exactly the case a check on the
 * template source would pass.
 */
#[CoversNothing]
final class StyleguideOffersEveryPreferenceTest extends TestCase
{
    private static ?HTMLDocument $page = null;

    /** @return iterable<string, array{string, string}> */
    public static function everyAnswer(): iterable
    {
        foreach (self::catalogue()->all() as $name => $preference) {
            foreach ($preference->values as $value) {
                yield $name . ' = ' . $value => [$name, $value];
            }
        }
    }

    #[DataProvider('everyAnswer')]
    public function testThereIsAControlForIt(string $preference, string $value): void
    {
        self::assertNotNull(
            $this->page()->querySelector(sprintf(
                '[data-preference="%s"][data-preference-value="%s"]',
                $preference,
                $value,
            )),
            sprintf(
                "'%s' is one of the answers this build accepts for %s and the style guide offers no "
                . 'control for it; add one to %s.',
                $value,
                $preference,
                'src/Core/Presentation/Styleguide/templates/Overview/default.latte',
            ),
        );
    }

    /** And nothing is offered that this build would refuse. */
    public function testEveryControlNamesSomethingThisBuildAccepts(): void
    {
        $catalogue = self::catalogue();

        $unknown = [];
        foreach ($this->page()->querySelectorAll('[data-preference]') as $control) {
            $preference = $control->getAttribute('data-preference') ?? '';
            $value = $control->getAttribute('data-preference-value') ?? '';

            if (!$catalogue->accepts($preference, $value)) {
                $unknown[] = $preference . ' = ' . $value;
            }
        }

        self::assertSame([], $unknown);
    }

    private function page(): HTMLDocument
    {
        return self::$page ??= HTMLDocument::createFromString(
            Build::render(
                Boot::container(
                    ModuleList::of(['cms' => true, 'crm' => true, 'shop' => true], Bootstrap::rootDirectory()),
                    styleguide: true,
                ),
                'Core:Styleguide:Overview',
            ),
            LIBXML_NOERROR,
        );
    }

    private static function catalogue(): PreferenceCatalogue
    {
        return PreferenceCatalogue::of(DesignSystem::of(Bootstrap::rootDirectory(), 'atrium'));
    }
}
