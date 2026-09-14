<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Admin;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Dom\HTMLDocument;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nette\Http\Request as HttpRequest;
use Nette\Http\UrlScript;
use Nette\Routing\Router;
use Nette\Security\AuthenticationException;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\PasswordLinks;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The page a password is set on, reached by the link somebody was sent.
 *
 * It answers to anybody, because whoever opens it has no password to sign in
 * with - the link is the key. So what it must not do is tell a link that never
 * existed from one that was used, replaced or let run out: each is the same
 * page, with the same status and the same words.
 */
#[CoversNothing]
final class PasswordPageTest extends TestCase
{
    /** The presenter the link leads to. */
    private const string PAGE = 'Core:Admin:Invitation';

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

    public function testTheLinkSetsThePasswordAndSendsTheirOwnerToSignIn(): void
    {
        $token = $this->links()->issue($this->invited());
        $chosen = Random::generate(16);

        $page = $this->pageOf($this->open($token));
        self::assertNotNull($page->querySelector('form[data-testid="password-form"]'));
        self::assertStringContainsString('ivo@example.com', $page->querySelector('[data-testid="admin-content"]')->textContent ?? '');

        $response = $this->submit($token, $chosen, $chosen);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('admin/sign-in', $response->getUrl());
        $this->container()->getByType(SignedIn::class)->login('ivo@example.com', $chosen);
        self::assertTrue($this->container()->getByType(SignedIn::class)->isLoggedIn());
    }

    /**
     * Never given, used, replaced by a newer one, past its week: one page,
     * one status, one sentence, and no form.
     */
    public function testEveryLinkThatDoesNotOpenIsRefusedTheSameWay(): void
    {
        $account = $this->invited();

        $used = $this->links()->issue($account);
        $chosen = Random::generate(16);
        $this->submit($used, $chosen, $chosen);

        $other = $this->invited('uma@example.com');
        $replaced = $this->links()->issue($other);
        $this->links()->issue($other);

        $expired = $this->links()->issue($this->invited('ada@example.com'));
        $this->connection()->executeStatement(
            'UPDATE core_password_link SET expires_at = ? WHERE account_id = (SELECT id FROM core_user WHERE email = ?)',
            [new DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s'), 'ada@example.com'],
        );

        $refusals = [];
        foreach (['never given' => Random::generate(43, 'A-Za-z0-9_-'), 'used' => $used, 'replaced' => $replaced, 'expired' => $expired] as $case => $token) {
            $page = $this->pageOf($this->open($token));

            self::assertSame(IResponse::S404_NotFound, $this->response()->getCode(), $case);
            self::assertNull($page->querySelector('form[data-testid="password-form"]'), $case . ': the form was offered');
            $refusal = $page->querySelector('[data-testid="password-refused"]');
            self::assertNotNull($refusal, $case . ': nothing said the link does not open');
            $refusals[$case] = trim($refusal->textContent ?? '');
        }

        self::assertCount(1, array_unique($refusals), 'the refusals differ, and the difference tells which link was which');
    }

    public function testALinkThatDoesNotOpenSetsNothingWhenItsFormIsPostedAnyway(): void
    {
        $used = $this->links()->issue($this->invited());
        $first = Random::generate(16);
        $this->submit($used, $first, $first);

        $second = Random::generate(16);
        $this->submit($used, $second, $second);

        $this->expectException(AuthenticationException::class);
        $this->container()->getByType(SignedIn::class)->login('ivo@example.com', $second);
    }

    public function testAPasswordShorterThanTwelveCharactersIsRefused(): void
    {
        $token = $this->links()->issue($this->invited());

        $this->assertNotSet($token, 'eleven-char', 'eleven-char');
    }

    public function testThePasswordCannotBeTheAddress(): void
    {
        $token = $this->links()->issue($this->invited());

        $this->assertNotSet($token, 'ivo@example.com', 'ivo@example.com');
    }

    public function testThePasswordHasToBeTypedTheSameTwice(): void
    {
        $token = $this->links()->issue($this->invited());

        $this->assertNotSet($token, Random::generate(16), Random::generate(16));
    }

    public function testTheLinkIsAnAddressOfItsOwnOutsideTheAdministration(): void
    {
        $matched = $this->container()->getByType(Router::class)->match(new HttpRequest(new UrlScript('http://localhost/_password/abc-DEF_123', '/')));

        self::assertIsArray($matched);
        self::assertSame(self::PAGE, $matched['presenter'] ?? null);
        self::assertSame('default', $matched['action'] ?? null);
        self::assertSame('abc-DEF_123', $matched['token'] ?? null);
    }

    private function assertNotSet(string $token, string $chosen, string $again): void
    {
        $response = $this->submit($token, $chosen, $again);

        self::assertInstanceOf(TextResponse::class, $response, 'the form was taken');
        self::assertNotNull(
            $this->pageOf($response)->querySelector('[data-testid="password-form"] .c-field__error, [data-testid="password-form"] .c-notice'),
            'the form was refused without saying why',
        );
        self::assertNotNull($this->links()->holderOf($token), 'a refused form spent the link');
    }

    private function open(string $token): Response
    {
        $this->response()->setCode(IResponse::S200_OK);

        return $this->serve(new Request(self::PAGE, 'GET', ['action' => 'default', 'token' => $token]));
    }

    private function submit(string $token, string $chosen, string $again): Response
    {
        $this->response()->setCode(IResponse::S200_OK);

        return $this->serve(new Request(
            self::PAGE,
            'POST',
            ['action' => 'default', 'token' => $token, 'do' => 'password-submit'],
            ['password' => $chosen, 'passwordAgain' => $again, 'set' => 'Set the password'],
        ));
    }

    private function serve(Request $request): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter($request->getPresenterName());
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run($request);
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function invited(string $email = 'ivo@example.com'): User
    {
        $account = User::invited($email, 'Ivo Isopod', new DateTimeImmutable());
        $this->container()->getByType(Accounts::class)->save($account);

        return $account;
    }

    private function links(): PasswordLinks
    {
        return $this->container()->getByType(PasswordLinks::class);
    }

    private function response(): IResponse
    {
        return $this->container()->getByType(IResponse::class);
    }

    private function connection(): Connection
    {
        return $this->container()->getByType(Connection::class);
    }

    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $this->container = Boot::coreAlone();
        Migrations::run($this->container);
        Tenants::enter($this->container, 'Ammonite Bikes', 'localhost');

        return $this->container;
    }
}
