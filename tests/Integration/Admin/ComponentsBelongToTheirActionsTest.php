<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Application\BadRequestException;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\Admin\Signals\FormMadeInStartupPresenter;
use Trilobit\Tests\Double\Admin\Signals\MultipliedFormsPresenter;
use Trilobit\Tests\Double\Admin\Signals\NotedPresenter;
use Trilobit\Tests\Double\Admin\Signals\SignalDouble;
use Trilobit\Tests\Double\Admin\Signals\UndeclaredFormPresenter;
use Trilobit\Tests\Double\Admin\Signals\UndeclaredSignalPresenter;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * A component of an administration page answers a signal only on the actions
 * it names, and a component or a signal that names none is a mistake raised
 * out loud - measured by posting to pages built to be posted to.
 *
 * The rule is enforced by Trilobit\Core\Presentation\Admin\AdminPresenter and
 * read for the whole build by
 * Trilobit\Tests\Architecture\EveryAdministrationComponentSaysWhereItBelongsTest.
 * What is here is the part the build cannot show: that it holds while a
 * request runs, including for the shapes a reading of the source would not
 * find - a form added in startup(), and forms made by a Multiplier.
 *
 * Every page is gated on reading, with editing above its `edit` action, and
 * the account signed in holds both. No gate refuses anything here, so a
 * request that is refused is refused by the rule about where a component
 * belongs and by nothing else - and each refusal is paired with the same form
 * accepted where it belongs, so that a form that never worked cannot pass for
 * one that was refused.
 */
#[CoversNothing]
final class ComponentsBelongToTheirActionsTest extends TestCase
{
    private string $schema = '';

    private ?Container $container = null;

    private ?string $fetchSite = null;

    protected function setUp(): void
    {
        $this->fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) && is_string($_SERVER['HTTP_SEC_FETCH_SITE'])
            ? $_SERVER['HTTP_SEC_FETCH_SITE']
            : null;
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    }

    protected function tearDown(): void
    {
        if ($this->fetchSite === null) {
            unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        } else {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = $this->fetchSite;
        }

        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testAFormIsSubmittedOnTheActionItNamesWhateverItsHandlerIsCalled(): void
    {
        self::assertSame(['jotted down'], $this->post(NotedPresenter::class, 'edit', 'note')->done);
    }

    public function testAFormPostedToAnActionItDoesNotNameIsNotThereToBeSubmitted(): void
    {
        $this->assertNotFound(NotedPresenter::class, 'default', 'note');
        $this->assertNotFound(NotedPresenter::class, 'nowhere', 'note');
    }

    /**
     * Each form of a Multiplier is made by a closure, two levels below the
     * presenter. The Multiplier is what the presenter's factory made, so it is
     * where the rule is asked - and a row's form posted to the list is refused
     * with it.
     */
    public function testAFormMadeByAMultiplierBelongsWhereTheMultiplierDoes(): void
    {
        self::assertSame(['row 3'], $this->post(MultipliedFormsPresenter::class, 'edit', 'notes-3')->done);

        $this->assertNotFound(MultipliedFormsPresenter::class, 'default', 'notes-3');
    }

    /**
     * A form added in startup() comes from no factory, so there is nothing to
     * read a declaration off. It is refused by where it came from: a signal
     * reaches only a component a declared factory made.
     */
    public function testAFormAddedInStartupIsAMistakeRaisedOutLoud(): void
    {
        foreach (['edit', 'default'] as $action) {
            $presenter = $this->presenter(FormMadeInStartupPresenter::class);

            $this->assertRaised(
                fn() => $this->send($presenter, $action, 'note'),
                'comes from no createComponent',
            );
            self::assertSame([], $presenter->done, 'a form added in startup() was submitted on ' . $action);
        }
    }

    public function testAFormWhoseFactoryNamesNoActionIsAMistakeRaisedOutLoud(): void
    {
        $presenter = $this->presenter(UndeclaredFormPresenter::class);

        $this->assertRaised(fn() => $this->send($presenter, 'edit', 'note'), 'does not say which actions');
        self::assertSame([], $presenter->done);
    }

    public function testASignalOfThePresenterThatNamesNoActionIsAMistakeRaisedOutLoud(): void
    {
        $presenter = $this->presenter(UndeclaredSignalPresenter::class);

        $this->assertRaised(
            fn(): Response => $presenter->run(new Request('Double:Signals', 'GET', ['action' => 'edit', 'do' => 'forget'])),
            'does not say which actions',
        );
        self::assertSame([], $presenter->done);
    }

    /** @param class-string<SignalDouble> $class */
    private function assertNotFound(string $class, string $action, string $form): void
    {
        $presenter = $this->presenter($class);

        try {
            $this->send($presenter, $action, $form);
            self::fail(sprintf('%s answered the form %s posted to %s', $class, $form, $action));
        } catch (BadRequestException $refused) {
            self::assertSame(404, $refused->getHttpCode());
        }

        self::assertSame([], $presenter->done);
    }

    private function assertRaised(\Closure $request, string $saying): void
    {
        try {
            $request();
            self::fail('the request was answered rather than raised as a mistake in the source');
        } catch (\LogicException $mistake) {
            self::assertStringContainsString($saying, $mistake->getMessage());
        }
    }

    /**
     * @template T of SignalDouble
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function post(string $class, string $action, string $form): SignalDouble
    {
        $presenter = $this->presenter($class);
        $this->send($presenter, $action, $form);

        return $presenter;
    }

    private function send(SignalDouble $presenter, string $action, string $form): void
    {
        $presenter->run(new Request(
            'Double:Signals',
            'POST',
            ['action' => $action, 'do' => $form . '-submit'],
            ['text' => 'Something', 'send' => 'Save'],
        ));
    }

    /**
     * A page built the way the framework builds one: made by the container,
     * with everything a presenter is handed injected into it.
     *
     * @template T of SignalDouble
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function presenter(string $class): SignalDouble
    {
        $container = $this->container();
        $presenter = $container->createInstance($class);
        self::assertInstanceOf($class, $presenter);
        $container->callInjects($presenter);
        $presenter->autoCanonicalize = false;

        return $presenter;
    }

    /**
     * A build, a business, and somebody in it who may read and edit its
     * content - enough that no gate refuses any request made here. The
     * password is generated, as in every suite that signs anybody in.
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => false, 'crm' => false, 'shop' => false],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);
        $tenant = Tenants::enter($container, 'Ammonite Bikes', Tenants::HOST);

        $password = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'cleo@example.com',
            $container->getByType(Passwords::class)->hash($password),
            'Cleo Crinoid',
            new DateTimeImmutable('2026-09-14T08:00:00+00:00'),
        );
        $container->getByType(Accounts::class)->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $role = new Role('writer', 'Writer', ['app.administration.content:view', 'app.administration.content:edit']);
        $entityManager->persist($role);
        $entityManager->persist(new Membership($tenant, $account, $role));
        $entityManager->flush();

        $container->getByType(SignedIn::class)->login('cleo@example.com', $password);

        return $this->container = $container;
    }
}
