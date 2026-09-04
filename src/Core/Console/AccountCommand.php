<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use DateTimeImmutable;
use Nette\Security\Passwords;
use Nette\Utils\Random;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;

/**
 * Makes somebody who can sign in, which a freshly installed application
 * otherwise has nobody to.
 *
 * The password is generated here and shown once. It is not an argument and not
 * an option: an argument is in the shell history of the machine it was typed
 * on and in that machine's process list while the command runs, and neither is
 * a place for it. It is not written to a file either - what is stored is the
 * hash.
 *
 * Run again for an address that already exists it replaces the password rather
 * than refusing. That is what somebody who has lost theirs needs, and what a
 * deployment script that calls this every time needs; making a second account
 * for one address is what nobody needs.
 */
#[AsCommand(
    name: 'app:account',
    description: 'Creates an administrator account, or gives an existing one a new password.',
)]
final class AccountCommand extends Command
{
    /**
     * How the generated password appears in the output: on a line of its own,
     * indented, with nothing else on it.
     *
     * It is a constant because it is a contract. tests/e2e signs in with a
     * real browser and reads the password back out of this output, and
     * Trilobit\Tests\Integration\Console\AccountCommandTest holds the format
     * still so that rewording the prose around it cannot quietly break that.
     */
    public const string PASSWORD_LINE = '/^ {2}(\S+)$/m'; // check-leaks:allow rule=credential reason=a regular expression that finds the line, not a value that was on it

    /** Long enough that it is not worth attacking, short enough to be typed once. */
    private const int GENERATED_LENGTH = 24;

    /** The role a first account has to hold for the administration to be worth opening. */
    private const string ROLE_CODE = 'administrator';

    private const string ROLE_NAME = 'Administrator';

    /** @var list<string> */
    private const array ROLE_PERMISSIONS = ['administration'];

    public function __construct(
        private readonly Accounts $accounts,
        private readonly Passwords $passwords,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The address the account signs in with.');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'What to call this person.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $style->error('The first argument has to be an address an account can sign in with.');

            return self::FAILURE;
        }

        $name = $input->getOption('name');
        $name = is_string($name) && $name !== '' ? $name : $this->nameFrom($email);

        $password = Random::generate(self::GENERATED_LENGTH, 'a-zA-Z0-9');
        $account = $this->accounts->withEmail($email);

        if (!$account instanceof User) {
            $account = new User($email, $this->passwords->hash($password), $name, new DateTimeImmutable());
            $existing = false;
        } else {
            $account->changePassword($this->passwords->hash($password));
            $account->rename($name);
            $existing = true;
        }

        $account->grant($this->accounts->roleWithCode(self::ROLE_CODE) ?? new Role(
            self::ROLE_CODE,
            self::ROLE_NAME,
            self::ROLE_PERMISSIONS,
        ));

        $this->accounts->save($account);

        $style->success(sprintf(
            '%s can sign in as %s.',
            $existing ? 'The account that was already there' : 'A new account',
            $email,
        ));
        $style->writeln('The password for that account is');
        $style->writeln('');
        $style->writeln('  ' . $password);
        $style->writeln('');
        $style->writeln('This is the only time it is shown. What is stored is a hash of it.');

        return self::SUCCESS;
    }

    /** Something to put on the page before anybody has said what to call them. */
    private function nameFrom(string $email): string
    {
        return ucfirst(explode('@', $email)[0]);
    }
}
