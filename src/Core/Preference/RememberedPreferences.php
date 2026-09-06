<?php

declare(strict_types=1);

namespace Trilobit\Core\Preference;

use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Security\User as SignedIn;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Identity;

/**
 * Where a choice about the way the application looks is kept, and which copy of
 * it wins. Decision D8 of .ai/plans/01d-design-system.md.
 *
 * There are two places, and they answer two different questions. The
 * **cookies** belong to the device - one per preference, see
 * Trilobit\Core\Preference\Preference::cookie() - and they are what a visitor
 * who has never signed in has, and what a page is drawn out of. The **profile**
 * belongs to the person: it is what somebody carries from one device to the
 * next.
 *
 * The cookies are written even for somebody who is signed in, and that is not
 * redundancy. A page has to be drawn in the right theme before anything reaches
 * the database - a first render out of the default followed by a correction
 * would flash the wrong colours at every visitor, and nobody would report it as
 * a fault because it looks like slowness. So the device is a cache of the
 * profile rather than a competitor to it, and the ordering that keeps the two
 * honest is:
 *
 * - the page is drawn out of the device, always;
 * - a change writes the device, and the profile too when there is one;
 * - signing in lets the profile win, because the device may be borrowed;
 * - **except** where the profile has no opinion, which takes the device's -
 *   somebody who chose a theme before registering does not lose it by signing
 *   in;
 * - signing out changes nothing, because a device keeping the look it had is
 *   what anybody would expect.
 *
 * ## What is stored, and why a build can still change its mind
 *
 * Only a deliberate choice is ever written down. Rendering a page stores
 * nothing, so a visitor who has not touched the switch has no cookie, no row
 * and no opinion - and follows trilobit.theme, which is the configuration of
 * this deployment.
 *
 * That is the answer to the thing this file was originally written to avoid: a
 * remembered value silently disagreeing with the build and somebody reporting a
 * bug about a theme nobody set. It cannot happen to somebody who never chose,
 * because nothing was stored for them; and where it does happen, somebody chose
 * it, which is not a disagreement but the feature. What is left of the risk is
 * the case where a build **removes or renames** a theme, and that is closed a
 * second way: every stored value goes through
 * Trilobit\Core\Preference\PreferenceCatalogue::reconcile(), which drops a value
 * this build no longer has. A stale name therefore falls back to configuration
 * rather than rendering an attribute no stylesheet answers, which would be a
 * page with no colours at all.
 */
final readonly class RememberedPreferences
{
    /**
     * Long enough that a choice is not quietly forgotten over a holiday. It is
     * refreshed on every change rather than on every request: a page that reset
     * the clock while merely being read would be a write on a GET.
     */
    private const string LIFETIME = '1 year';

    public function __construct(
        private PreferenceCatalogue $catalogue,
        private IRequest $httpRequest,
        private IResponse $httpResponse,
        private Accounts $accounts,
    ) {}

    /**
     * What this request is drawn with.
     *
     * Read off the device and never off the database, which is the whole reason
     * the cookies exist beside the profile.
     */
    public function forThisRequest(): Preferences
    {
        return $this->catalogue->reconcile($this->onTheDevice());
    }

    /** A deliberate change: the device always, and the profile too when there is one. */
    public function remember(string $name, string $value, SignedIn $user): void
    {
        if (!$this->catalogue->accepts($name, $value)) {
            return;
        }

        $this->writeToTheDevice($name, $value);

        $account = $this->accountOf($user);
        if ($account instanceof User) {
            $account->prefer($name, $value);
            $this->accounts->save($account);
        }
    }

    /**
     * Signing in: what the person carries wins over what the device remembers,
     * except where the person carries nothing.
     *
     * Hung on Nette\Security\User::$onLoggedIn rather than called from the
     * sign-in page, so that a second way of signing in - a link in an e-mail, a
     * single sign-on - is not a second place to remember this.
     *
     * What the profile says is read off the identity, which was built from the
     * account a moment earlier by Trilobit\Core\Security\Identity::of(), rather
     * than fetched again here.
     */
    public function whenSomebodySignsIn(SignedIn $user): void
    {
        $identity = $user->getIdentity();

        $device = $this->catalogue->reconcile($this->onTheDevice());
        $profile = $this->catalogue->reconcile($identity instanceof Identity ? $identity->preferences() : []);

        // The profile's entries overwrite the device's, and the device's
        // survive wherever the profile has none. Only what really changes is
        // written back, so a device that already agreed is left as it is.
        $merged = $this->catalogue->reconcile([...$device->chosen(), ...$profile->chosen()]);
        foreach ($merged->chosen() as $name => $value) {
            if (($device->chosen()[$name] ?? null) !== $value) {
                $this->writeToTheDevice($name, $value);
            }
        }

        $adopted = [];
        foreach ($device->chosen() as $name => $value) {
            if (!$profile->chose($name)) {
                $adopted[$name] = $value;
            }
        }

        // The database is reached for only when there is something to write to
        // it. Signing in already costs a read of the account; making it cost a
        // second one to find out that both sides agree would be a query on
        // every sign-in for the case that is by far the most common.
        if ($adopted === []) {
            return;
        }

        $account = $this->accountOf($user);
        if (!$account instanceof User) {
            return;
        }

        foreach ($adopted as $name => $value) {
            $account->prefer($name, $value);
        }

        $this->accounts->save($account);
    }

    /**
     * What the device says, one cookie per preference.
     *
     * A cookie nobody wrote is a preference nobody chose, which is why the
     * absent ones are simply left out rather than filled in. Nothing here is
     * trusted either - a cookie is a string a browser sent - so what comes back
     * still goes through the catalogue before anything is drawn with it.
     *
     * @return array<string, string>
     */
    private function onTheDevice(): array
    {
        $chosen = [];
        foreach ($this->catalogue->all() as $name => $preference) {
            $value = $this->httpRequest->getCookie($preference->cookie());
            if (is_string($value) && $value !== '') {
                $chosen[$name] = $value;
            }
        }

        return $chosen;
    }

    /**
     * Writes one choice, and only ever a choice: a preference nobody has an
     * opinion about has no cookie at all, so an absent one says "no opinion"
     * rather than "the default of the build that wrote it".
     *
     * The cookie is not readable from JavaScript. Nothing in the browser needs
     * it: the server writes the choice onto the html element, which is where
     * the page reads it from, and the switch applies its own change to that
     * element without consulting anything.
     */
    private function writeToTheDevice(string $name, string $value): void
    {
        $this->httpResponse->setCookie(
            $this->catalogue->preference($name)->cookie(),
            $value,
            self::LIFETIME,
            path: '/',
            secure: $this->httpRequest->isSecured(),
            httpOnly: true,
        );
    }

    /** The account behind the identity in the session, or null while nobody is signed in. */
    private function accountOf(SignedIn $user): ?User
    {
        if (!$user->isLoggedIn()) {
            return null;
        }

        $id = $user->getId();

        return is_int($id) ? $this->accounts->withId($id) : null;
    }
}
