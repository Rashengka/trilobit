<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Admin;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dom\HTMLDocument;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\ForwardResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nette\Http\Request as HttpRequest;
use Nette\Http\UrlScript;
use Nette\Mail\Mailer;
use Nette\Routing\Router;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Domain\Tenancy\Membership;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Grant;
use Trilobit\Core\Security\PasswordLinks;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Double\Mail\CapturingMailer;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * The people of a business, from the pages somebody manages them on.
 *
 * What Trilobit\Tests\Integration\Security\PeopleTest asks of the service is
 * asked here of the pages, and one question more: whether every way a page
 * offers into a guarded act - a form, a form posted by hand with a value the
 * form never offered, a removal posted for a role the page did not draw a
 * button for, another action name, somebody else's business - ends where the
 * service says. A button that is not drawn is a courtesy; the refusal behind it
 * is the guard.
 *
 * The mail a build sends in a test is kept rather than sent
 * (Trilobit\Tests\Double\Mail\CapturingMailer), and made to refuse where the
 * claim is what a person sees when mail cannot go.
 */
#[CoversNothing]
final class PeopleAdministrationTest extends TestCase
{
    private const string PEOPLE = 'Core:Admin:People';

    private const string REFUSAL = 'Core:Error:Refusal';

    private const string MANAGER = 'moss@example.com';

    private string $schema = '';

    private ?Container $container = null;

    private ?string $fetchSite = null;

    /** @var array<string, string> by the address the account signs in with */
    private array $passwords = [];

    /** @var array<string, Role> by code */
    private array $roles = [];

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
        $this->passwords = [];
        $this->roles = [];

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testThePeopleOfThisBusinessAreListedAndNobodyElse(): void
    {
        $this->signIn('alice@example.com');

        $list = $this->contentOf($this->request('default'));

        foreach (['alice@example.com', self::MANAGER, 'eddie@example.com', 'nora@example.com', 'ivo@example.com'] as $email) {
            self::assertStringContainsString($email, $list);
        }
        self::assertStringNotContainsString('bruno@example.com', $list, 'somebody of another business is listed here');
    }

    public function testTheListSaysWhoIsStillWaitingForTheirInvitation(): void
    {
        $this->signIn('alice@example.com');

        $page = $this->pageOf($this->request('default'));

        $ivo = $page->querySelector('[data-testid="people-state-' . $this->personId('ivo@example.com') . '"]');
        $nora = $page->querySelector('[data-testid="people-state-' . $this->personId('nora@example.com') . '"]');
        self::assertSame('Invited', trim($ivo->textContent ?? ''));
        self::assertSame('Active', trim($nora->textContent ?? ''));
    }

    public function testTheListIsFilteredFromTheAddress(): void
    {
        $this->signIn('alice@example.com');

        $list = $this->contentOf($this->request('default', ['people-name' => 'Nautilus']));

        self::assertStringContainsString('nora@example.com', $list);
        self::assertStringNotContainsString('eddie@example.com', $list);
    }

    public function testSomebodyWhoMayNotSeeWhoBelongsHereIsRefusedTheList(): void
    {
        foreach (['eddie@example.com', 'nora@example.com'] as $email) {
            $this->signIn($email);
            $this->assertRefusedByTheGate($this->request('default'));
        }
    }

    public function testOnlySomebodyWhoMayAddPeopleIsShownTheFormForSomebodyNew(): void
    {
        $this->signIn('vera@example.com');

        $this->assertRefusedByTheGate($this->request('add'));
        self::assertNull(
            $this->pageOf($this->request('default'))->querySelector('[data-testid="people-add"]'),
            'the list offers a way to add somebody to a person who may not',
        );
    }

    /**
     * Adding somebody new sends them the link that sets their password, and
     * the link in the message is one that opens.
     */
    public function testAddingSomebodyNewSendsThemTheLinkTheySetTheirPasswordWith(): void
    {
        $this->signIn('alice@example.com');

        $response = $this->addSomebody('nell@example.org', 'Nell Newt', 'editor');

        self::assertInstanceOf(RedirectResponse::class, $response);
        $sent = $this->mailer()->sentTo('nell@example.org');
        self::assertCount(1, $sent);
        self::assertSame('nell@example.org', $this->container()->getByType(PasswordLinks::class)->holderOf($this->tokenIn($sent[0]->getBody()))?->email());
        self::assertStringContainsString('went', $this->toastsOf($this->follow($response)));
    }

    /**
     * Mail that did not go is said on the screen - not the success it would
     * otherwise look like - and the invitation can be sent again from there.
     */
    public function testAnInvitationThatDidNotGoIsSaidAndCanBeSentAgain(): void
    {
        $this->signIn('alice@example.com');
        $this->mailer()->refusing = 'Nothing answered on the port the server was named on.';

        $response = $this->addSomebody('nell@example.org', 'Nell Newt', 'editor');

        self::assertSame([], $this->mailer()->sentTo('nell@example.org'));
        $page = $this->pageOf($this->follow($response));
        $danger = $page->querySelector('[data-testid="toasts"] .c-toast__item--danger');
        self::assertNotNull($danger, 'the page did not say the invitation failed');
        self::assertStringContainsString('did not go', $danger->textContent ?? '');
        self::assertNotNull($page->querySelector('[data-testid="people-resend"]'), 'the page offers no way to send it again');

        $this->mailer()->refusing = null;
        $again = $this->request('person', ['id' => (string) $this->personId('nell@example.org'), 'do' => 'resend-submit'], ['send' => 'Send the invitation again']);

        self::assertInstanceOf(RedirectResponse::class, $again);
        $sent = $this->mailer()->sentTo('nell@example.org');
        self::assertCount(1, $sent);
        self::assertNotNull($this->container()->getByType(PasswordLinks::class)->holderOf($this->tokenIn($sent[0]->getBody())));
    }

    public function testAddingSomebodyWhoHasAnAccountSaysSoAndSendsNothing(): void
    {
        $this->signIn('alice@example.com');

        $response = $this->addSomebody('bruno@example.com', 'Somebody Else', 'editor');

        self::assertSame([], $this->mailer()->sent);
        self::assertStringContainsString('already had an account', $this->toastsOf($this->follow($response)));
    }

    public function testTheRoleInBetweenIsOfferedOnlyWhatItMayGive(): void
    {
        $this->signIn(self::MANAGER);

        $page = $this->pageOf($this->request('add'));

        $offered = [];
        foreach ($page->querySelectorAll('[data-testid="people-addition"] select[name="role"] option') as $option) {
            $value = $option->getAttribute('value') ?? '';
            if ($value !== '') {
                $offered[] = (int) $value;
            }
        }
        sort($offered);
        $expected = [$this->roleId('onlooker'), $this->roleId('people'), $this->roleId('viewer')];
        sort($expected);

        self::assertSame($expected, $offered);
    }

    /** A role the form never offered, posted by hand, adds nobody. */
    public function testARoleTheFormDidNotOfferAddsNobodyWhenPostedAnyway(): void
    {
        $this->signIn(self::MANAGER);

        foreach (['editor', Role::OWNER] as $code) {
            $this->addSomebody('nell-' . $code . '@example.org', 'Nell Newt', $code);
            self::assertNull($this->accounts()->withEmail('nell-' . $code . '@example.org'), 'the role in between added somebody as ' . $code);
        }
        self::assertSame([], $this->mailer()->sent);
    }

    /** The form for somebody new, posted to a page that is not the form's, is still asked who may. */
    public function testTheFormForSomebodyNewPostedFromAnotherPageIsAskedAllTheSame(): void
    {
        $this->signIn('vera-viewer@example.com');

        $this->request('default', ['do' => 'addition-submit'], [
            'email' => 'nell@example.org',
            'name' => 'Nell Newt',
            'role' => (string) $this->roleId('onlooker'),
            'add' => 'Add',
        ]);

        self::assertNull($this->accounts()->withEmail('nell@example.org'));
    }

    public function testRemovingIsASubmitAndTakesTheMembershipAway(): void
    {
        $this->signIn('alice@example.com');
        $nora = $this->personId('nora@example.com');
        $held = $this->membershipId('nora@example.com', 'onlooker');

        self::assertNotNull(
            $this->pageOf($this->request('person', ['id' => (string) $nora]))->querySelector('form[data-testid="people-remove-' . $held . '"]'),
            'there is no button taking the role away',
        );

        $response = $this->remove($nora, $held);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(0, $this->membershipCount($held));
    }

    /**
     * The role in between is shown no button for a role it could not have
     * given - and a removal posted for it anyway is refused, out loud.
     */
    public function testARemovalPostedForARoleThatMayNotBeTakenIsRefused(): void
    {
        $this->signIn(self::MANAGER);
        $eddie = $this->personId('eddie@example.com');
        $held = $this->membershipId('eddie@example.com', 'editor');

        $page = $this->pageOf($this->request('person', ['id' => (string) $eddie]));
        self::assertNull($page->querySelector('form[data-testid="people-remove-' . $held . '"]'));
        self::assertNotNull($page->querySelector('[data-testid="people-unchangeable"]'), 'the page does not say why nothing can be changed');

        $response = $this->remove($eddie, $held);

        self::assertSame(1, $this->membershipCount($held));
        self::assertNotNull($this->pageOf($this->follow($response))->querySelector('[data-testid="toasts"] .c-toast__item--danger'));
    }

    public function testNobodyIsOfferedAWayToChangeTheirOwnMembershipNorGivenOneWhenTheyPostIt(): void
    {
        $this->signIn('alice@example.com');
        $alice = $this->personId('alice@example.com');
        $held = $this->membershipId('alice@example.com', Role::OWNER);

        $page = $this->pageOf($this->request('person', ['id' => (string) $alice]));
        self::assertNull($page->querySelector('form[data-testid="people-remove-' . $held . '"]'));
        self::assertStringContainsString('This is you', $page->querySelector('[data-testid="people-unchangeable"]')->textContent ?? '');

        $this->remove($alice, $held);

        self::assertSame(1, $this->membershipCount($held));
    }

    public function testSomebodyOfAnotherBusinessIsNotFoundHere(): void
    {
        $this->signIn('alice@example.com');

        try {
            $this->request('person', ['id' => (string) $this->personId('bruno@example.com')]);
        } catch (BadRequestException $notFound) {
            self::assertSame(404, $notFound->getHttpCode());

            return;
        }

        self::fail('somebody of another business was shown here');
    }

    public function testTheirAddressesAreThePeoplePagesOfTheAdministration(): void
    {
        self::assertSame([self::PEOPLE, 'default'], $this->routed('/admin/people'));
        self::assertSame([self::PEOPLE, 'add'], $this->routed('/admin/people/add'));
        self::assertSame([self::PEOPLE, 'person'], $this->routed('/admin/people/7'));
    }

    private function addSomebody(string $email, string $name, string $role): Response
    {
        return $this->request('add', ['do' => 'addition-submit'], [
            'email' => $email,
            'name' => $name,
            'role' => (string) $this->roleId($role),
            'add' => 'Add',
        ]);
    }

    private function remove(int $person, int $membership): Response
    {
        return $this->request('person', ['id' => (string) $person, 'do' => 'removal-' . $membership . '-submit'], ['remove' => 'Remove']);
    }

    private function assertRefusedByTheGate(Response $response): void
    {
        self::assertInstanceOf(ForwardResponse::class, $response, 'the gate let the request through');
        self::assertSame(self::REFUSAL, $response->getRequest()->getPresenterName());
    }

    private function tokenIn(string $body): string
    {
        self::assertSame(1, preg_match('~/_password/([A-Za-z0-9_-]+)~', quoted_printable_decode($body), $match), "no link in:\n" . $body);

        return $match[1] ?? '';
    }

    private function mailer(): CapturingMailer
    {
        $mailer = $this->container()->getByType(Mailer::class);
        self::assertInstanceOf(CapturingMailer::class, $mailer);

        return $mailer;
    }

    /** The page a redirect leads to, with the flash messages it carries. */
    private function follow(Response $response): Response
    {
        self::assertInstanceOf(RedirectResponse::class, $response);
        $matched = $this->container()->getByType(Router::class)->match(new HttpRequest(new UrlScript($response->getUrl(), '/')));
        self::assertIsArray($matched, $response->getUrl() . ' is not an address of this application');

        $presenter = $matched['presenter'] ?? null;
        self::assertIsString($presenter);
        unset($matched['presenter']);

        $parameters = [];
        foreach ($matched as $name => $value) {
            if (is_scalar($value)) {
                $parameters[$name] = (string) $value;
            }
        }

        return $this->runRequest(new Request($presenter, 'GET', $parameters));
    }

    private function toastsOf(Response $response): string
    {
        return trim($this->pageOf($response)->querySelector('[data-testid="toasts"]')->textContent ?? '');
    }

    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $post
     */
    private function request(string $action, array $parameters = [], array $post = []): Response
    {
        return $this->runRequest(new Request(self::PEOPLE, $post === [] ? 'GET' : 'POST', ['action' => $action, ...$parameters], $post));
    }

    private function runRequest(Request $request): Response
    {
        $presenter = $this->container()->getByType(IPresenterFactory::class)->createPresenter($request->getPresenterName());
        self::assertInstanceOf(Presenter::class, $presenter);
        $presenter->autoCanonicalize = false;

        return $presenter->run($request);
    }

    /** @return array{string, string} */
    private function routed(string $address): array
    {
        $matched = $this->container()->getByType(Router::class)->match(new HttpRequest(new UrlScript('http://localhost' . $address, '/')));
        self::assertIsArray($matched, $address . ' is not claimed by the router');
        self::assertIsString($matched['presenter'] ?? null);
        self::assertIsString($matched['action'] ?? null);

        return [$matched['presenter'], $matched['action']];
    }

    private function pageOf(Response $response): HTMLDocument
    {
        self::assertInstanceOf(TextResponse::class, $response);
        $source = $response->getSource();
        self::assertInstanceOf(\Stringable::class, $source);

        return HTMLDocument::createFromString((string) $source, LIBXML_NOERROR);
    }

    private function contentOf(Response $response): string
    {
        $content = $this->pageOf($response)->querySelector('[data-testid="admin-content"]');
        self::assertNotNull($content, 'the page drew no content');

        return trim((string) preg_replace('/\s+/', ' ', $content->textContent ?? ''));
    }

    private function signIn(string $email): void
    {
        $signedIn = $this->container()->getByType(SignedIn::class);
        $signedIn->logout(true);
        $signedIn->login($email, $this->passwords[$email] ?? self::fail('no account ' . $email));
        $this->container()->getByType(IResponse::class)->setCode(IResponse::S200_OK);
    }

    private function accounts(): Accounts
    {
        return $this->container()->getByType(Accounts::class);
    }

    private function personId(string $email): int
    {
        return $this->accounts()->withEmail($email)?->id() ?? self::fail('there is no account ' . $email);
    }

    private function roleId(string $code): int
    {
        $this->container();

        return ($this->roles[$code] ?? self::fail('no role ' . $code))->id() ?? self::fail('the role ' . $code . ' was never saved');
    }

    private function membershipId(string $email, string $code): int
    {
        $id = $this->connection()->fetchOne(
            'SELECT m.id FROM core_tenant_membership m JOIN core_role r ON r.id = m.role_id'
            . ' JOIN core_user u ON u.id = m.user_id WHERE u.email = ? AND r.code = ?',
            [$email, $code],
        );

        return is_numeric($id) ? (int) $id : self::fail($email . ' holds no ' . $code);
    }

    private function membershipCount(int $id): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM core_tenant_membership WHERE id = ?', [$id]);

        return is_numeric($count) ? (int) $count : self::fail('the membership could not be counted');
    }

    private function connection(): Connection
    {
        return $this->container()->getByType(Connection::class);
    }

    /**
     * Two businesses. In the first an owner, the role in between, two people
     * who may only see who belongs here, an editor, somebody holding nothing, and somebody still waiting
     * for their link; in the second somebody holding its own role.
     */
    private function container(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        $this->container = $container;
        Migrations::run($container);
        $entityManager = $container->getByType(EntityManagerInterface::class);

        $belemnite = Tenants::enter($container, 'Belemnite Books', 'belemnite.localhost');
        $proofreader = Role::ofBusiness($belemnite, 'proofreader', 'Proofreader', [new Grant(Resource::Content, Privilege::View)->code()]);
        $entityManager->persist($proofreader);
        $entityManager->flush();
        $this->member($belemnite, 'bruno@example.com', 'Bruno Belemnite', $proofreader);

        $made = Tenants::enter($container, 'Ammonite Bikes', 'localhost');
        // Moving into a second business empties the entity manager, so the
        // business is read back rather than held on to as it was made.
        $ammonite = $entityManager->find(Tenant::class, $made->id()) ?? self::fail('the business cannot be read back');

        $this->roles[Role::OWNER] = $this->accounts()->ownersRole($container->getByType(PermissionStructure::class));
        $entityManager->flush();
        $this->roles['people'] = $this->role($ammonite, 'people', [Privilege::View, Privilege::Add, Privilege::Edit, Privilege::Delete]);
        $this->roles['viewer'] = $this->role($ammonite, 'viewer', [Privilege::View]);
        $this->roles['onlooker'] = $this->role($ammonite, 'onlooker', []);
        $editor = Role::ofBusiness($ammonite, 'editor', 'Editor', [new Grant(Resource::Content, Privilege::View)->code(), new Grant(Resource::Content, Privilege::Edit)->code()]);
        $entityManager->persist($editor);
        $entityManager->flush();
        $this->roles['editor'] = $editor;

        $this->member($ammonite, 'alice@example.com', 'Alice Ammonite', $this->roles[Role::OWNER]);
        $this->member($ammonite, self::MANAGER, 'Moss Mosasaur', $this->roles['people']);
        $this->member($ammonite, 'vera@example.com', 'Vera Volute', $this->roles['viewer']);
        $this->member($ammonite, 'vera-viewer@example.com', 'Vic Volute', $this->roles['viewer']);
        $this->member($ammonite, 'eddie@example.com', 'Eddie Echinoid', $editor);
        $this->member($ammonite, 'nora@example.com', 'Nora Nautilus', $this->roles['onlooker']);

        $ivo = User::invited('ivo@example.com', 'Ivo Isopod', new DateTimeImmutable());
        $this->accounts()->save($ivo);
        $entityManager->persist(new Membership($ammonite, $ivo, $this->roles['onlooker']));
        $entityManager->flush();
        $container->getByType(PasswordLinks::class)->issue($ivo);

        return $container;
    }

    /** @param list<Privilege> $privileges on the accounts of this business */
    private function role(Tenant $business, string $code, array $privileges): Role
    {
        $role = Role::ofBusiness($business, $code, ucfirst($code), array_map(
            static fn(Privilege $privilege): string => new Grant(Resource::Account, $privilege)->code(),
            $privileges,
        ));
        $entityManager = $this->container()->getByType(EntityManagerInterface::class);
        $entityManager->persist($role);
        $entityManager->flush();

        return $role;
    }

    private function member(Tenant $business, string $email, string $name, Role $role): void
    {
        $container = $this->container ?? self::fail('no build yet');
        $password = Random::generate(20);
        $this->passwords[$email] = $password;

        $account = new User($email, $container->getByType(Passwords::class)->hash($password), $name, new DateTimeImmutable());
        $this->accounts()->save($account);

        $entityManager = $container->getByType(EntityManagerInterface::class);
        $entityManager->persist(new Membership($business, $account, $role));
        $entityManager->flush();
    }
}
