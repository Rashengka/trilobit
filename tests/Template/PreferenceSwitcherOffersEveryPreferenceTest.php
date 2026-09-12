<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Preference\Preferences;
use Trilobit\Core\Presentation\Design\DesignSystem;

/**
 * Every preference this build has, and every answer it accepts, is something
 * somebody can actually pick - and nothing is offered that the build would
 * refuse.
 *
 * The claim is made of c-preference-switcher itself rather than of a page,
 * because the switch is one component drawn in two places: at the top of the
 * style guide and in the menu of whoever is signed in to the administration. A
 * page would only say something about that page.
 *
 * The component reads its lists out of the catalogue, so the two cannot drift
 * apart by somebody forgetting a line - which is exactly why this has to be
 * checked rather than assumed: a condition that skips an answer, or a list
 * written back into the template "for the labels", drifts in silence in both
 * directions. A value with no control is a mode nobody can reach, and a control
 * for a value the catalogue does not have posts a choice the server answers
 * with 400 while the page carries on looking right, because the switch had
 * already changed it.
 */
#[CoversNothing]
final class PreferenceSwitcherOffersEveryPreferenceTest extends TestCase
{
    private static ?HTMLDocument $drawn = null;

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
            $this->drawn()->querySelector($this->control($preference, $value)),
            sprintf(
                "'%s' is one of the answers this build accepts for %s and c-preference-switcher offers no "
                . 'control for it; see %s.',
                $value,
                $preference,
                'src/Core/Presentation/components/preference-switcher.latte',
            ),
        );
    }

    /** And nothing is offered that this build would refuse. */
    public function testEveryControlNamesSomethingThisBuildAccepts(): void
    {
        $catalogue = self::catalogue();

        $unknown = [];
        foreach ($this->drawn()->querySelectorAll('[data-preference]') as $control) {
            $preference = $control->getAttribute('data-preference') ?? '';
            $value = $control->getAttribute('data-preference-value') ?? '';

            if (!$catalogue->accepts($preference, $value)) {
                $unknown[] = $preference . ' = ' . $value;
            }
        }

        self::assertSame([], $unknown);
    }

    /**
     * The one pressed is the one in force, and it is said once, in
     * aria-pressed - one per preference and never two.
     */
    public function testTheChosenAnswerIsTheOnePressed(): void
    {
        $drawn = $this->draw(self::catalogue()->reconcile(['theme' => 'ledger']));

        self::assertSame('true', $drawn->querySelector($this->control('theme', 'ledger'))?->getAttribute('aria-pressed'));
        self::assertSame('false', $drawn->querySelector($this->control('theme', 'atrium'))?->getAttribute('aria-pressed'));

        foreach (self::catalogue()->names() as $name) {
            self::assertCount(
                1,
                $drawn->querySelectorAll(sprintf('[data-preference="%s"][aria-pressed="true"]', $name)),
                sprintf('%s is drawn with other than exactly one answer pressed', $name),
            );
        }
    }

    /**
     * The switch shows the person's own setting, not what the page it is on
     * insists on. A switch that showed the exception as the setting would save
     * it the next time anybody clicked anything - see
     * Trilobit\Core\Preference\Preferences::preferred().
     */
    public function testItShowsWhatThePersonPrefersRatherThanWhatThePageInsistsOn(): void
    {
        $drawn = $this->draw(self::catalogue()->reconcile([])->overruledWith('content-width', 'full'));

        self::assertSame(
            'true',
            $drawn->querySelector($this->control('content-width', 'content'))?->getAttribute('aria-pressed'),
        );
        self::assertSame(
            'false',
            $drawn->querySelector($this->control('content-width', 'full'))?->getAttribute('aria-pressed'),
        );
    }

    private function drawn(): HTMLDocument
    {
        return self::$drawn ??= $this->draw(self::catalogue()->reconcile([]));
    }

    private function draw(Preferences $preferences): HTMLDocument
    {
        return ComponentRendering::render(
            'preference-switcher.latte',
            '{include preferenceSwitcher, preferences: $preferences}',
            ['preferences' => $preferences],
        );
    }

    private function control(string $preference, string $value): string
    {
        return sprintf('[data-preference="%s"][data-preference-value="%s"]', $preference, $value);
    }

    private static function catalogue(): PreferenceCatalogue
    {
        return PreferenceCatalogue::of(DesignSystem::of(Bootstrap::rootDirectory(), 'atrium'));
    }
}
