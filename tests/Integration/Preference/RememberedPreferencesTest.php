<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Preference;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Preference\PreferenceCatalogue;
use Trilobit\Core\Preference\Preferences;
use Trilobit\Core\Preference\RememberedPreferences;
use Trilobit\Core\Presentation\Design\DesignSystem;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\RecordingHttpResponse;
use Trilobit\Tests\Double\StandInHttpRequest;
use Trilobit\Tests\Migrations;

/**
 * Decision D8: a choice about the way the application looks is remembered, on
 * the device and - once there is somebody to remember it for - on the person.
 *
 * The two halves are exercised as they really are: the device is a cookie on a
 * request and a cookie on a response, and the person is a row. Signing in is
 * done through Nette\Security\User rather than by calling the synchronisation
 * directly, because half of what D8 asks for is that the synchronisation
 * happens at all - a rule hung on the wrong event is a rule that never runs and
 * leaves no trace when it does not.
 *
 * The three claims that are easiest to get backwards each have a case of their
 * own below: the profile wins, a profile with nothing to say takes what the
 * device has, and signing out leaves the device alone.
 */
#[CoversNothing]
final class RememberedPreferencesTest extends TestCase
{
    private const string ADDRESS = 'alice@example.com';

    private const string OTHER_THEME = 'ledger';

    private string $schema = '';

    private ?Container $container = null;

    private string $password = '';

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    /**
     * What a build starts in is configuration, and somebody who has never
     * touched the switch follows it. That is the whole of why remembering does
     * not fight trilobit.theme.
     */
    public function testAVisitorWhoHasChosenNothingIsGivenWhatTheBuildSays(): void
    {
        $preferences = $this->remembered()->forThisRequest();

        self::assertSame([], $preferences->chosen());
        self::assertSame($this->configuredTheme(), $preferences->value(PreferenceCatalogue::THEME));
        self::assertSame('system', $preferences->value(PreferenceCatalogue::THEME_MODE));
    }

    /**
     * And nothing is written down for them either. A page that stored the
     * default while merely being read would turn a deployment's later change of
     * mind into a bug report about a theme nobody set - which is exactly the
     * risk this mechanism was weighed against.
     */
    public function testDrawingAPageWritesNothingDown(): void
    {
        $this->remembered()->forThisRequest();

        self::assertSame(0, $this->written()->timesWritten($this->themeCookie()));
        self::assertSame(0, $this->written()->timesWritten($this->modeCookie()));
    }

    public function testAChoiceIsKeptOnTheDevice(): void
    {
        $this->remembered()->remember(PreferenceCatalogue::THEME, self::OTHER_THEME, $this->visitor());

        self::assertSame(self::OTHER_THEME, $this->written()->cookie($this->themeCookie()));
        self::assertTrue(
            $this->written()->isHttpOnly($this->themeCookie()),
            'nothing in the browser reads it - the server writes the choice onto the html element',
        );
    }

    /**
     * The mistake the cookie-per-preference shape exists to prevent: two
     * choices made before either has come back.
     *
     * Read, changed and written back into one cookie, the second would drop the
     * first - and it would look like nothing, because the switch had already
     * changed the page in front of the person. The loss would show on the next
     * load, by which time there is nothing to connect it to. Both calls below
     * see the device exactly as it was, which is what the second request of a
     * pair really sees.
     */
    public function testTwoChoicesMadeBeforeEitherComesBackBothSurvive(): void
    {
        $this->remembered()->remember(PreferenceCatalogue::THEME, self::OTHER_THEME, $this->visitor());
        $this->remembered()->remember(PreferenceCatalogue::THEME_MODE, 'dark', $this->visitor());

        $device = $this->deviceNowSays();

        self::assertSame(self::OTHER_THEME, $device->value(PreferenceCatalogue::THEME));
        self::assertSame('dark', $device->value(PreferenceCatalogue::THEME_MODE));
    }

    /**
     * How wide the content is rides the mechanism the theme rides, and adding it
     * was a line in the catalogue. It gets a cookie of its own, named after
     * itself, written by the same call and read back by the same one - which is
     * what "no second place to keep how somebody wants the application to look"
     * amounts to in practice (.ai/plans/09-chrome-a-sirka-obsahu.md, L4).
     */
    public function testTheWidthSomebodyChoseIsKeptOnACookieOfItsOwn(): void
    {
        $cookie = $this->catalogue()->preference(PreferenceCatalogue::CONTENT_WIDTH)->cookie();
        self::assertSame('trilobit-content-width', $cookie);

        $this->remembered()->remember(PreferenceCatalogue::CONTENT_WIDTH, 'full', $this->visitor());

        self::assertSame('full', $this->written()->cookie($cookie));

        $device = $this->deviceNowSays();
        self::assertSame('full', $device->value(PreferenceCatalogue::CONTENT_WIDTH));
        self::assertSame(
            $this->configuredTheme(),
            $device->value(PreferenceCatalogue::THEME),
            'choosing a width said something about the theme, so the two are not kept apart',
        );
    }

    /**
     * A build that renames or removes a theme takes its holders back to
     * configuration rather than leaving them on an attribute no stylesheet
     * answers, which would be a page with no colours at all.
     */
    public function testAValueThisBuildNoLongerHasIsIgnored(): void
    {
        $this->device()->carry($this->themeCookie(), 'a-theme-that-was-deleted');

        $preferences = $this->remembered()->forThisRequest();

        self::assertFalse($preferences->chose(PreferenceCatalogue::THEME));
        self::assertSame($this->configuredTheme(), $preferences->value(PreferenceCatalogue::THEME));
    }

    /**
     * D8, rule one. The device may be borrowed or shared; what somebody carries
     * with them is the profile, so the profile wins.
     */
    public function testTheProfileWinsWhenSomebodySignsIn(): void
    {
        $this->account()->prefer(PreferenceCatalogue::THEME, self::OTHER_THEME);
        $this->accounts()->save($this->account());

        $this->device()->carry($this->themeCookie(), $this->configuredTheme());

        $this->signIn();

        self::assertSame(self::OTHER_THEME, $this->deviceNowSays()->value(PreferenceCatalogue::THEME));
    }

    /**
     * D8, rule two, and the exception to rule one. Somebody who picked a theme
     * before they had an account does not lose it by signing in - and the
     * profile takes it over, so the next device gets it too.
     */
    public function testAProfileWithNoChoiceOfItsOwnTakesTheOneFromTheDevice(): void
    {
        self::assertSame([], $this->account()->preferences());

        $this->device()->carry($this->themeCookie(), self::OTHER_THEME);

        $this->signIn();

        self::assertSame([PreferenceCatalogue::THEME => self::OTHER_THEME], $this->savedProfile());
        self::assertSame(self::OTHER_THEME, $this->deviceNowSays()->value(PreferenceCatalogue::THEME));
    }

    /**
     * D8, rule three. Signing out is not a change of mind about the way the
     * application looks, and a device that changed colour on the way out would
     * be the one thing nobody expects of a sign-out.
     */
    public function testSigningOutLeavesTheDeviceItsChoice(): void
    {
        $this->device()->carry($this->themeCookie(), self::OTHER_THEME);

        $this->signIn();
        $writtenWhileSigningIn = $this->written()->timesWritten($this->themeCookie());

        $this->container()->getByType(SignedIn::class)->logout(clearIdentity: true);

        self::assertSame(
            $writtenWhileSigningIn,
            $this->written()->timesWritten($this->themeCookie()),
            'signing out touched the cookie the device keeps its appearance in',
        );
        self::assertFalse($this->written()->wasDeleted($this->themeCookie()));
        self::assertSame(
            self::OTHER_THEME,
            $this->remembered()->forThisRequest()->value(PreferenceCatalogue::THEME),
        );
    }

    /** D8: a change writes the profile as the truth and the device as the quick way to the next page. */
    public function testAChangeBySomebodySignedInIsWrittenToBothTheProfileAndTheDevice(): void
    {
        $this->signIn();

        $this->remembered()->remember(
            PreferenceCatalogue::THEME,
            self::OTHER_THEME,
            $this->container()->getByType(SignedIn::class),
        );

        self::assertSame([PreferenceCatalogue::THEME => self::OTHER_THEME], $this->savedProfile());
        self::assertSame(self::OTHER_THEME, $this->deviceNowSays()->value(PreferenceCatalogue::THEME));
    }

    /**
     * What the device would send on its next request: whatever it was last told
     * to keep, or what it was already carrying if it was told nothing.
     *
     * Both are the same claim - "this device now looks like that" - and which
     * of the two happens is not the point. It is what a browser does, and
     * asserting only on a write would fail the case where the device already
     * held the answer and there was nothing to correct.
     */
    private function deviceNowSays(): Preferences
    {
        foreach ($this->catalogue()->all() as $preference) {
            $written = $this->written()->cookie($preference->cookie());
            if ($written !== null) {
                $this->device()->carry($preference->cookie(), $written);
            }
        }

        return $this->remembered()->forThisRequest();
    }

    private function themeCookie(): string
    {
        return $this->catalogue()->preference(PreferenceCatalogue::THEME)->cookie();
    }

    private function modeCookie(): string
    {
        return $this->catalogue()->preference(PreferenceCatalogue::THEME_MODE)->cookie();
    }

    private function catalogue(): PreferenceCatalogue
    {
        return $this->container()->getByType(PreferenceCatalogue::class);
    }

    /** The profile as the database holds it, not as the object in memory happens to look. */
    private function savedProfile(): mixed
    {
        $this->container()->getByType(EntityManagerInterface::class)->clear();

        $account = $this->accounts()->withEmail(self::ADDRESS);
        self::assertInstanceOf(User::class, $account);

        return $account->preferences();
    }

    private function signIn(): void
    {
        $this->container()->getByType(SignedIn::class)->login(self::ADDRESS, $this->password);
    }

    /** Nobody in particular: the switch has an anonymous visitor in front of it most of the time. */
    private function visitor(): SignedIn
    {
        return $this->container()->getByType(SignedIn::class);
    }

    private function account(): User
    {
        $account = $this->accounts()->withEmail(self::ADDRESS);
        self::assertInstanceOf(User::class, $account);

        return $account;
    }

    private function accounts(): Accounts
    {
        return $this->container()->getByType(Accounts::class);
    }

    private function remembered(): RememberedPreferences
    {
        return $this->container()->getByType(RememberedPreferences::class);
    }

    private function device(): StandInHttpRequest
    {
        $request = $this->container()->getByType(StandInHttpRequest::class);
        self::assertInstanceOf(StandInHttpRequest::class, $request);

        return $request;
    }

    private function written(): RecordingHttpResponse
    {
        $response = $this->container()->getByType(RecordingHttpResponse::class);
        self::assertInstanceOf(RecordingHttpResponse::class, $response);

        return $response;
    }

    private function configuredTheme(): string
    {
        return $this->container()->getByType(DesignSystem::class)->defaultTheme;
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(
            ModuleList::of([], Bootstrap::rootDirectory()),
            config: ['services' => [
                'http.request' => ['factory' => StandInHttpRequest::class],
                'http.response' => ['factory' => RecordingHttpResponse::class],
            ]],
        );
        Migrations::run($container);

        $this->password = Random::generate(24, 'a-zA-Z0-9');
        $container->getByType(Accounts::class)->save(new User(
            self::ADDRESS,
            $container->getByType(Passwords::class)->hash($this->password),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-06T08:00:00+00:00'),
        ));

        return $this->container = $container;
    }
}
