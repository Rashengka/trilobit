<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Admin;

use DateTimeImmutable;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Authenticator;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;

/**
 * The administration, from outside it: who gets in, who is turned away, and
 * what being turned away looks like.
 *
 * Signing in happens through the presenter and its form rather than by calling
 * the authenticator, because the claim is about the pages a person meets. The
 * account is made by this suite and its password generated here - nothing that
 * could be signed in with is in the repository.
 *
 * Two mechanics of the framework are set up rather than worked around. The
 * request carries the signal a submitted form carries, which is what makes the
 * form read the posted values; and the environment carries the Sec-Fetch-Site
 * header a browser sends, which is what nette/forms 3.3 checks in place of a
 * token in the page - its own CSRF control is deprecated as redundant beside
 * it. Neither weakens anything: both are what a real browser posting this form
 * produces, and tests/e2e signs in through a real one.
 */
#[CoversNothing]
final class AdministrationTest extends TestCase
{
    private const string SIGN = 'Core:Admin:Sign';

    private const string DASHBOARD = 'Core:Admin:Dashboard';

    private string $schema = '';

    private ?Container $container = null;

    private string $generatedPassword = '';

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

    /**
     * The claim T07 is measured by: a visitor who is not signed in is sent
     * somewhere rather than shown a stack trace. The status code is asserted
     * as well as the destination, because a 500 carrying a Location header
     * would satisfy "goes to the sign-in page" and nothing else about it.
     */
    public function testAnAnonymousVisitorIsSentToTheSignInPage(): void
    {
        $response = $this->request(self::DASHBOARD, 'default');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(302, $response->getCode());
        self::assertStringContainsString('admin/sign-in', $response->getUrl());
    }

    public function testTheSignInPageIsOpenToEverybody(): void
    {
        $page = $this->pageOf($this->request(self::SIGN, 'in'));

        self::assertNotNull($page->querySelector('[data-testid="sign-in-form"]'));
        self::assertNotNull($page->querySelector('[data-testid="sign-in-email"]'));
        self::assertNotNull($page->querySelector('[data-testid="sign-in-submit"]'));
    }

    /**
     * There is nothing to navigate to before there is somebody navigating, and
     * the build under test has three modules contributing entries - so an
     * empty menu here is the page leaving it out rather than there being none.
     */
    public function testTheSignInPageCarriesNoAdministrationMenu(): void
    {
        $page = $this->pageOf($this->request(self::SIGN, 'in'));

        self::assertNull($page->querySelector('[data-testid="admin-menu"]'));
    }

    public function testTheRightPasswordOpensTheAdministration(): void
    {
        $response = $this->submitSignIn('alice@example.com', $this->password());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringNotContainsString('sign-in', $response->getUrl());
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    /**
     * The two ways to fail are told apart nowhere a visitor can see. A page
     * that said "no such address" would be an address checker anybody could
     * run against it.
     */
    public function testAWrongPasswordAndAnUnknownAddressAreRefusedInTheSameWords(): void
    {
        $wrongPassword = $this->refusalIn($this->submitSignIn('alice@example.com', 'not the one that was set'));
        $unknownAddress = $this->refusalIn($this->submitSignIn('nobody@example.com', $this->password()));

        self::assertSame(Authenticator::REFUSAL, $wrongPassword);
        self::assertSame($wrongPassword, $unknownAddress);
        self::assertFalse($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    public function testOnceSignedInTheOverviewSaysWhoIsSignedIn(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $page = $this->pageOf($this->request(self::DASHBOARD, 'default'));

        self::assertNotNull($page->querySelector('[data-testid="admin-layout"]'));
        self::assertSame('Overview', $page->querySelector('[data-testid="admin-headline"]')?->textContent);
        self::assertSame('Alice Ammonite', $page->querySelector('[data-testid="admin-identity"]')?->textContent);
        self::assertSame(
            'alice@example.com',
            $page->querySelector('[data-testid="admin-identity-email"]')?->textContent,
        );
        self::assertNotNull($page->querySelector('[data-testid="admin-role-administrator"]'));
        self::assertNotNull($page->querySelector('[data-testid="admin-permission-administration"]'));
    }

    public function testSigningOutSendsYouBackToTheSignInPage(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());

        $response = $this->request(self::SIGN, 'out');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('admin/sign-in', $response->getUrl());
        self::assertFalse($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    /** Somebody already signed in has no business on the sign-in page. */
    public function testSomebodySignedInIsTakenStraightToTheOverview(): void
    {
        $this->submitSignIn('alice@example.com', $this->password());

        $response = $this->request(self::SIGN, 'in');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringNotContainsString('sign-in', $response->getUrl());
    }

    private function submitSignIn(string $email, string $secret): Response
    {
        return $this->request(self::SIGN, 'in', ['do' => 'signIn-submit'], [
            'email' => $email,
            'password' => $secret,
            'send' => 'Sign in',
        ]);
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $post
     */
    private function request(string $presenterName, string $action, array $parameters = [], array $post = []): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter($presenterName);
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run(new Request(
            $presenterName,
            $post === [] ? 'GET' : 'POST',
            ['action' => $action, ...$parameters],
            $post,
        ));
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function refusalIn(Response $response): string
    {
        $error = $this->pageOf($response)->querySelector('[data-testid="sign-in-error"]');
        self::assertNotNull($error, 'the page said nothing about the sign-in having failed');

        return trim($error->textContent ?? '');
    }

    private function password(): string
    {
        $this->container();

        return $this->generatedPassword;
    }

    /** A build with every module on, so that the menu the administration draws is not empty. */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::container(ModuleList::of(
            ['cms' => true, 'crm' => true, 'shop' => true],
            Bootstrap::rootDirectory(),
        ));
        Migrations::run($container);

        $this->generatedPassword = Random::generate(24, 'a-zA-Z0-9');
        $account = new User(
            'alice@example.com',
            $container->getByType(Passwords::class)->hash($this->generatedPassword),
            'Alice Ammonite',
            new DateTimeImmutable('2026-09-04T08:00:00+00:00'),
        );
        $account->grant(new Role('administrator', 'Administrator', ['administration']));
        $container->getByType(Accounts::class)->save($account);

        return $this->container = $container;
    }
}
