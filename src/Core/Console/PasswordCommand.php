<?php

declare(strict_types=1);

namespace Trilobit\Core\Console;

use Nette\Security\Passwords;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\Accounts;

/**
 * Lets the person an account belongs to choose their own password, by typing it
 * here instead of being handed one.
 *
 * It is one half of a pair, and the halves differ in exactly one thing: where
 * the password comes from. Trilobit\Core\Console\AccountCommand generates one,
 * prints it once and stores its hash, which is the shape a deployment script
 * wants and the shape a fresh installation is set up with. This one is the
 * shape a person wants once the account is theirs, and nothing about it can be
 * automated - on purpose.
 *
 * **The address is an argument; the password is not, and there is no option for
 * it either.** `app:account` and `app:tenant` both put the subject of the
 * command in the first argument, so a third command that reached for an option
 * instead would be a third convention bought for nothing. The password goes the
 * other way for the reason AccountCommand already gives: an argument is in the
 * shell history of the machine it was typed on and in that machine's process
 * list while the command runs, and neither of those is a place for it. There is
 * deliberately nothing here to reach for when somebody wants to pass one in.
 *
 * **It is hidden while it is typed, and it is asked twice.** The confirmation
 * is not ceremony. Hidden input is the one case where a typo cannot be seen, so
 * it is the only kind that has to be asked again: a password nobody can
 * reproduce is an account nobody can sign in to, and that is found out at the
 * worst possible moment, by the person it belongs to, with no way back.
 *
 * **A run that cannot ask is refused, and told where to go instead.** Answering
 * a hidden question needs somebody at a keyboard; a run started with
 * --no-interaction, or with nothing on its input to read, has nobody. Refusing
 * is the honest end there, because every other end is worse - a password read
 * out of a pipe came from a file or a here-document, which is the shell history
 * this command exists to keep it out of. What a script actually wants is
 * `app:account`, and the refusal says so rather than leaving somebody to guess.
 *
 * **An address nobody has is an error and never a new account.** Making one is
 * `app:account`'s job, and it stays its job: an address mistyped here would
 * otherwise quietly become a second account, and the person would go on
 * wondering why their old password still worked.
 *
 * **What was typed is not written down anywhere.** It is never printed back,
 * not even masked; it is not an argument, so it reaches neither the shell
 * history nor the process list; and it is passed to exactly one thing that is
 * not local to this class, Nette\Security\Passwords::hash(), whose parameter is
 * marked sensitive so that a stack trace out of it cannot carry the value into
 * a log. What is stored is the hash it returns.
 *
 * **There is no way to name a business, and that is today's answer rather than
 * a permanent one.** An address is unique across this installation - see the
 * column on Trilobit\Core\Domain\User\User - so a business named here would
 * narrow nothing and would only pretend to be choosing between accounts.
 * **Exit condition:** the moment an address stops being unique across the
 * installation, this command has to be told whose account it is changing.
 * Until it is, it would change the first one it happened to find, which is a
 * wrong answer that looks exactly like a right one.
 */
#[AsCommand(
    name: 'app:password',
    description: 'Sets the password of an account that already exists, typed by the person it belongs to.',
)]
final class PasswordCommand extends Command
{
    /**
     * How short a password may be, and the only thing about its shape that is
     * checked at all.
     *
     * Length is what costs an attacker: every character multiplies the work,
     * while a rule demanding a digit or a capital multiplies almost nothing and
     * pushes people towards the handful of shapes such rules produce - a word,
     * a capital at the front, a digit and a mark at the end - which is the
     * first thing anybody guessing would try. So there is no such rule here,
     * and twelve is the floor because it is long enough that a passphrase of a
     * few ordinary words clears it without thinking while nothing anybody would
     * reach for absent-mindedly does.
     */
    public const int MINIMUM_LENGTH = 12;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly Passwords $passwords,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'email',
            InputArgument::REQUIRED,
            'The address of the account whose password is being changed.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $style->error('The first argument has to be the address of an account that can sign in.');

            return self::FAILURE;
        }

        if (!$input->isInteractive()) {
            $this->refuseWithNothingTyped(
                $style,
                'This run was told not to ask anything, and a password here is only ever typed.',
            );

            return self::FAILURE;
        }

        // Before asking rather than after: nobody should type a password twice
        // for an account that was never going to be found.
        $account = $this->accounts->withEmail($email);
        if (!$account instanceof User) {
            $style->error(sprintf('No account here signs in as %s.', $email));
            $style->writeln(
                'This command changes the password of an account that exists and never makes one, because '
                . 'making one is what app:account is for. An address typed wrongly here would otherwise become '
                . 'a second account, and the password of the real one would go on being what it was.',
            );

            return self::FAILURE;
        }

        $chosen = $this->chosenPassword($style, $email);

        if ($chosen === null) {
            // Refused, with the reason already said. Nothing has been written.
            return self::FAILURE;
        }

        $account->changePassword($this->passwords->hash($chosen));
        $this->accounts->save($account);

        $style->success(sprintf('%s now signs in with the password that was just typed.', $email));
        $style->writeln('It was not shown, written down or logged anywhere; what is stored is a hash of it.');

        return self::SUCCESS;
    }

    /**
     * The password somebody typed twice, or null when it was refused and the
     * reason has been said.
     *
     * It is judged before the confirmation is asked for, so that a password
     * that was never going to be accepted costs one entry instead of two, and
     * so that the refusal names one thing rather than leaving somebody to
     * wonder whether the mismatch was the real problem.
     *
     * Nothing is asked twice over: a refusal ends the run rather than looping.
     * A loop is a shape that can hold a terminal open, and running the command
     * again costs one line.
     */
    private function chosenPassword(SymfonyStyle $style, string $email): ?string
    {
        $first = $this->askHidden($style, 'The new password (it will not be shown as you type)');

        // An empty answer is the second face of "nobody is there". A run whose
        // input is exhausted - cron, a redirect from an empty file, a pipe
        // whose writer has gone - reads one rather than raising, so treating it
        // as a password that happens to be too short would answer the wrong
        // question with a message about length.
        if ($first === '') {
            $this->refuseWithNothingTyped($style, 'Nothing was typed, so nothing was changed.');

            return null;
        }

        if (strlen($first) < self::MINIMUM_LENGTH) {
            $style->error(sprintf('A password here is at least %d characters long.', self::MINIMUM_LENGTH));
            $style->writeln(
                'Length is the whole of the rule: there is nothing here about digits or capitals, because '
                . 'those cost an attacker almost nothing and push people towards the few predictable shapes '
                . 'such rules produce. A few ordinary words in a row is longer than anything a rule would ask '
                . 'for. Nothing was changed.',
            );

            return null;
        }

        if (strcasecmp($first, $email) === 0) {
            $style->error('That is the address the account signs in with, so it cannot also be the password.');
            $style->writeln(
                'It is the first thing anybody trying this account would type, and it is already written down '
                . 'everywhere the account is. Nothing was changed.',
            );

            return null;
        }

        $again = $this->askHidden($style, 'The same password again');

        if (!hash_equals($first, $again)) {
            $style->error('The two did not match, so nothing was changed.');
            $style->writeln(
                'Hidden input is the one kind where a typo cannot be seen, which is why it is asked twice. '
                . 'Run the command again.',
            );

            return null;
        }

        return $first;
    }

    /**
     * Asks for one entry without echoing it.
     *
     * The answer is trimmed, which is Symfony's default and is kept on purpose:
     * a space at either end cannot be seen in hidden input, and a password
     * whose first character is invisible is one its owner cannot reliably type
     * again. Both entries are trimmed the same way, so the confirmation still
     * compares what was actually meant.
     */
    private function askHidden(SymfonyStyle $style, string $question): string
    {
        $answer = $style->askHidden($question);

        return is_string($answer) ? $answer : '';
    }

    /**
     * The one refusal for both ways of arriving here with nothing to read,
     * written once so that the two cannot come to say different things about
     * one situation. Only the headline differs, because only the headline can
     * be true of one and false of the other.
     *
     * The first way is --no-interaction, which says so before anything is
     * asked. The second is an input with nothing on it, and it is worth writing
     * down what that actually does, because it is not what the name of
     * Symfony's MissingInputException suggests: measured against this build,
     * standard input redirected from /dev/null and a pipe with nothing written
     * to it both come back as the question's default rather than as a raised
     * exception, and the default here is nothing at all. So an empty answer is
     * the shape a machine arrives in, and it is handled where an empty answer
     * is handled rather than in a catch that would never run.
     */
    private function refuseWithNothingTyped(SymfonyStyle $style, string $headline): void
    {
        $style->error($headline);
        $style->writeln(
            'There is no --password and no second argument to pass one in, because either would put it in '
            . 'the shell history of this machine and in its process list while the command ran. What a '
            . 'script wants is the other half of the pair, which generates a password, prints it once and '
            . 'stores only a hash of it:',
        );
        $style->writeln('');
        $style->writeln('  bin/trilobit app:account <email>');
    }
}
