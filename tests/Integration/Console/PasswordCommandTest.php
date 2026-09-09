<?php

declare(strict_types=1);

namespace Trilobit\Tests\Integration\Console;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nette\DI\Container;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\Passwords;
use Nette\Security\User as SignedIn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Trilobit\Core\Console\PasswordCommand;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;
use Trilobit\Core\Security\Identity;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Database;
use Trilobit\Tests\Migrations;
use Trilobit\Tests\Tenants;

/**
 * `bin/trilobit app:password`, which is how the person an account belongs to
 * chooses their own password instead of being handed one.
 *
 * It is the other half of a pair, and what these assert is the seam between
 * them. Trilobit\Core\Console\AccountCommand generates a password and prints it
 * once, which is what a script needs; this one only ever takes one that was
 * typed, which is what a person needs. So the claims here are about what
 * happens when there is nobody to type - refusal, and a refusal that says where
 * to go instead - as much as about the ordinary path.
 *
 * Two of them are worth naming. **What was typed must not appear in the
 * output**, because hiding it while it is entered is undone by printing it back
 * afterwards, and nothing but a test would notice a line that had crept in.
 * **Every refusal must leave the account signing in with what it had**, because
 * a command that refuses and half-writes is worse than one that does neither:
 * somebody would be locked out by a message telling them nothing had changed.
 *
 * The two phrases below are named for what they are rather than for what they
 * hold. A constant called anything like "password" with a literal beside it is
 * a finding to bin/check-leaks, and rightly so - the rule cannot tell an
 * invented phrase in a test from a real one somewhere else, and a suppression
 * on each would teach the habit of excusing exactly that rule.
 */
#[CoversNothing]
final class PasswordCommandTest extends TestCase
{
    /** The account every case here starts from. */
    private const string EMAIL = 'alice@example.com';

    /** What it signs in with before the command runs. */
    private const string STARTING_PHRASE = 'the-one-it-started-with';

    /** What somebody types at the prompts when the case is about the ordinary path. */
    private const string CHOSEN_PHRASE = 'a-passphrase-of-several-words';

    private string $schema = '';

    private ?Container $container = null;

    protected function tearDown(): void
    {
        $this->container?->getByType(SignedIn::class)->logout(true);
        $this->container = null;

        if ($this->schema !== '') {
            Database::drop($this->schema);
            $this->schema = '';
        }
    }

    public function testItReplacesWhatTheAccountSignsInWithByWhatWasTypedTwice(): void
    {
        $container = $this->installationWithAnAccount();

        [$status, $output] = $this->outcomeOf($container, self::EMAIL, [self::CHOSEN_PHRASE, self::CHOSEN_PHRASE]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, self::CHOSEN_PHRASE));
        self::assertNull($this->signInWith($container, self::STARTING_PHRASE), 'the old one stopped working');
    }

    /**
     * The point of hiding the entry is lost if the command prints it back, and
     * the terminal it would be printed into is the one whoever walks past next
     * scrolls through.
     */
    public function testWhatWasTypedIsNeverPrinted(): void
    {
        $container = $this->installationWithAnAccount();

        [, $output] = $this->outcomeOf($container, self::EMAIL, [self::CHOSEN_PHRASE, self::CHOSEN_PHRASE]);

        self::assertStringNotContainsString(self::CHOSEN_PHRASE, $output);
    }

    /**
     * The reason there is a confirmation at all: hidden input is the one kind
     * where a typo cannot be seen. What matters as much as the refusal is that
     * neither entry was written - being locked out by a command that said it
     * had changed nothing is the worst outcome available here.
     */
    public function testTwoEntriesThatDoNotMatchChangeNothing(): void
    {
        $container = $this->installationWithAnAccount();

        [$status, $output] = $this->outcomeOf(
            $container,
            self::EMAIL,
            [self::CHOSEN_PHRASE, self::CHOSEN_PHRASE . '-typo'],
        );

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('did not match', $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, self::STARTING_PHRASE));
    }

    /** Twelve characters, and the refusal says that length is the whole of the rule. */
    public function testAnEntryShorterThanTheMinimumIsRefused(): void
    {
        $container = $this->installationWithAnAccount();

        $tooShort = str_repeat('a', PasswordCommand::MINIMUM_LENGTH - 1);
        [$status, $output] = $this->outcomeOf($container, self::EMAIL, [$tooShort]);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString((string) PasswordCommand::MINIMUM_LENGTH, $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, self::STARTING_PHRASE));
    }

    /**
     * One character longer is accepted, and it is a run of the same letter -
     * so the refusal above is about the length and about nothing else. A rule
     * about digits or capitals would fail this case, which is how this test
     * says that no such rule was added later.
     */
    public function testAnEntryExactlyTheMinimumLengthIsAcceptedWhateverItIsMadeOf(): void
    {
        $container = $this->installationWithAnAccount();

        $shortestAllowed = str_repeat('a', PasswordCommand::MINIMUM_LENGTH);
        [$status, $output] = $this->outcomeOf($container, self::EMAIL, [$shortestAllowed, $shortestAllowed]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, $shortestAllowed));
    }

    /**
     * The one entry refused for what it is rather than for how long it is. The
     * address is the first thing anybody trying this account would enter, and
     * it clears the length floor on its own.
     */
    public function testAnEntryEqualToTheAddressIsRefused(): void
    {
        $container = $this->installationWithAnAccount();

        self::assertGreaterThanOrEqual(PasswordCommand::MINIMUM_LENGTH, strlen(self::EMAIL));

        [$status, $output] = $this->outcomeOf($container, self::EMAIL, [strtoupper(self::EMAIL)]);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('address the account signs in with', $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, self::STARTING_PHRASE));
    }

    /**
     * An address nobody has is an error and never a new account. Making one is
     * app:account's job, and a typo here would otherwise leave somebody with a
     * second account while the real one went on as it was.
     */
    public function testAnAddressNobodyHasIsRefusedAndMakesNoAccount(): void
    {
        $container = $this->installationWithAnAccount();

        [$status, $output] = $this->outcomeOf(
            $container,
            'nobody@example.org',
            [self::CHOSEN_PHRASE, self::CHOSEN_PHRASE],
        );

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('nobody@example.org', $output);
        self::assertNull($container->getByType(Accounts::class)->withEmail('nobody@example.org'));
        self::assertCount(
            1,
            $container->getByType(EntityManagerInterface::class)->getRepository(User::class)->findAll(),
        );
    }

    /**
     * A run started with --no-interaction has nobody to ask, and the refusal
     * has to point at the command a script actually wants rather than leave it
     * to be guessed.
     *
     * The headline is asserted and not only the pointer. Both refusals name
     * app:account, so a test that looked for the pointer alone would go on
     * passing if this check were deleted - the run would fall through to the
     * question, read nothing, and be refused by the next check for a different
     * reason. That is a guard nothing would notice the loss of, which is the
     * one kind this project treats as absent.
     */
    public function testARunThatWasToldNotToAskIsRefusedAndNamesTheOtherCommand(): void
    {
        $container = $this->installationWithAnAccount();

        [$status, $output] = $this->outcomeOf($container, self::EMAIL, [], interactive: false);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('told not to ask', $output);
        self::assertStringContainsString('app:account', $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, self::STARTING_PHRASE));
    }

    /**
     * And the other way of having nobody there: interactivity was never
     * switched off, but there is nothing on the input to read - which is what
     * cron, a redirect from an empty file and a closed pipe all look like from
     * inside the command. It ends the same way, or the two would drift into
     * saying different things about one situation.
     */
    public function testARunWithNothingToReadIsRefusedTheSameWay(): void
    {
        $container = $this->installationWithAnAccount();

        [$status, $output] = $this->outcomeOf($container, self::EMAIL, []);

        self::assertSame(Command::FAILURE, $status, $output);
        self::assertStringContainsString('Nothing was typed', $output);
        self::assertStringContainsString('app:account', $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, self::STARTING_PHRASE));
    }

    /**
     * The command takes no business and needs none: an address is unique across
     * the installation today, so an account that administers one is reached by
     * its address like any other. This is the claim the exit condition in the
     * command's docblock is written against - when an address stops being
     * unique, this is where it will show.
     */
    public function testItAlsoServesAnAccountThatBelongsToABusiness(): void
    {
        $container = $this->installationWithAnAccount(landlord: false);
        Tenants::create($container, 'Ammonite Bikes', 'bikes.example.com');

        [$status, $output] = $this->outcomeOf($container, self::EMAIL, [self::CHOSEN_PHRASE, self::CHOSEN_PHRASE]);

        self::assertSame(Command::SUCCESS, $status, $output);
        self::assertInstanceOf(Identity::class, $this->signInWith($container, self::CHOSEN_PHRASE));
    }

    /** A fresh installation holding exactly one account, signing in with a phrase this test knows. */
    private function installationWithAnAccount(bool $landlord = true): Container
    {
        $this->schema = Database::schemaFor(self::class);
        $container = Boot::coreAlone();
        Migrations::run($container);

        $container->getByType(Accounts::class)->save(new User(
            self::EMAIL,
            $container->getByType(Passwords::class)->hash(self::STARTING_PHRASE),
            'Alice Ammonite',
            new DateTimeImmutable(),
            landlord: $landlord,
        ));

        return $this->container = $container;
    }

    /** The identity an entry earns, or null when it earns none - which is the whole of "does it still work". */
    private function signInWith(Container $container, string $entry): ?Identity
    {
        try {
            $identity = $container->getByType(Authenticator::class)->authenticate(self::EMAIL, $entry);
        } catch (AuthenticationException) {
            return null;
        }

        return $identity instanceof Identity ? $identity : null;
    }

    /**
     * @param list<string> $typed what somebody enters at the hidden prompts, in order
     * @return array{int, string}
     */
    private function outcomeOf(Container $container, string $email, array $typed, bool $interactive = true): array
    {
        $command = $container->getService('core.passwordCommand');
        self::assertInstanceOf(Command::class, $command);
        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $tester->setInputs($typed);

        $status = $tester->execute(
            ['email' => $email],
            ['capture_stderr_separately' => true, 'interactive' => $interactive],
        );

        return [$status, $tester->getDisplay() . $tester->getErrorOutput()];
    }
}
