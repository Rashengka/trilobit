<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Nette\Application\IPresenterFactory;
use Nette\Application\UI\Presenter;
use Nette\Security\User as SignedIn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Tests\Boot;

/**
 * The container's services are lazy - `di: lazy: true` in config/common.neon -
 * and this is what fails when they stop being.
 *
 * A lazy service is a native PHP 8.4 lazy object: the container hands it out
 * at once and runs its constructor and setup only when something first
 * touches it. What is asserted is therefore "initialised", not "created": a
 * lazy object exists from the moment it is injected, so a test that asked
 * whether the service had been created would pass with lazy services on and
 * off alike. See bin/measure-services for the same distinction on a whole
 * request.
 *
 * The user is the service it is asserted on because every presenter is handed
 * one, most requests never ask it anything, and behind it stands the longest
 * tree a presenter holds: the session storage, the authenticator with the
 * accounts and the entity manager behind them, the authorizator.
 */
#[CoversNothing]
final class LazyServicesTest extends TestCase
{
    public function testAServiceAPresenterWasHandedIsNotBuiltUntilSomethingAsksItAnything(): void
    {
        $container = Boot::container();
        $presenter = $container->getByType(IPresenterFactory::class)->createPresenter('Core:Front:Home');
        self::assertInstanceOf(Presenter::class, $presenter);

        // Asking the presenter for its user builds the presenter - its
        // constructor and its injections, which is where it was handed the
        // user - and asks the user nothing.
        $user = $presenter->getUser();

        self::assertSame(
            $container->getByType(SignedIn::class),
            $user,
            'The presenter was handed something other than the container\'s one user.',
        );
        self::assertTrue(
            $this->isUnbuilt($user),
            'The user was built merely because a presenter was handed it; the container\'s services are not lazy.',
        );

        // The first question builds it, and only it: what it holds is built
        // when that is asked something in turn.
        $authenticator = $user->getAuthenticator();

        self::assertFalse($this->isUnbuilt($user));
        self::assertTrue($this->isUnbuilt($authenticator), 'Building the user built the authenticator behind it as well.');
    }

    /**
     * The one risk of lazy services this build has to take on trust otherwise:
     * the tenant filter is switched on by a setup call on the entity manager
     * rather than in its constructor, and a lazy service's setup runs in the
     * same initialiser as its constructor. A query written before that setup
     * had run would come back with every tenant's rows. The first call the
     * manager answers is the moment to look, because it is the one that
     * builds it.
     */
    public function testTheTenantFilterIsOnFromTheFirstCallALazyEntityManagerAnswers(): void
    {
        $manager = Boot::container()->getByType(EntityManagerInterface::class);
        self::assertTrue($this->isUnbuilt($manager), 'Nothing has asked the entity manager anything yet.');

        self::assertTrue($manager->getFilters()->isEnabled('tenant'));
    }

    private function isUnbuilt(object $service): bool
    {
        return new \ReflectionClass($service)->isUninitializedLazyObject($service);
    }
}
