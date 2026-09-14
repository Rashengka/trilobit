<?php

declare(strict_types=1);

namespace Trilobit\Core\Mail;

use LogicException;
use Nette\Mail\FileMailer;
use Nette\Mail\Mailer;
use Nette\Mail\SmtpMailer;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Config\Mode;

/**
 * Which way mail leaves this deployment, as its environment says.
 *
 * Mail has one failure nothing else in the application has: a message that
 * went nowhere looks exactly like one that was sent, to the person who sent it
 * and to whoever was waiting for it. Everything here is arranged against that.
 *
 * **Nothing said is SMTP to the catcher compose.yaml starts** - Mailpit, on the
 * port compose.yaml publishes - the same way an empty database setting is the
 * database compose.yaml starts. A working copy's mail stays on the machine and
 * can be read in the catcher's inbox; a deployment that forgot to name its
 * server gets a refused connection the sender is told about, rather than
 * silence.
 *
 * **Writing mail to files is a working copy's alone.** It is what the browser
 * suite reads an invitation out of, and what a machine without Docker can use.
 * Anywhere else the build refuses it the moment mail is first asked for: a
 * message written into var/mail on a server is a message nobody receives while
 * the screen says it went.
 *
 * **A setting that cannot be right is refused, not read as the nearest thing
 * it might have meant.** A misspelt transport taken as SMTP would send real
 * mail from a machine whose owner believed it could not; an encryption the
 * client does not know would reach it as none, and send the password in the
 * clear.
 *
 * The password is read where it is handed to the client, which marks it
 * sensitive; it is never kept on this object, and the environment it comes
 * from never shows a value in a dump. Its variable is written out there in
 * full rather than kept in a constant beside the others: nothing else needs
 * to name it.
 */
final readonly class Mailers
{
    /** `smtp`, or empty for it, or `file` on a working copy. */
    public const string TRANSPORT = 'TRILOBIT_MAIL_TRANSPORT';

    public const string HOST = 'TRILOBIT_MAIL_HOST';

    public const string PORT = 'TRILOBIT_MAIL_PORT';

    public const string USER = 'TRILOBIT_MAIL_USER';

    /** `ssl`, `tls`, or empty for none. */
    public const string ENCRYPTION = 'TRILOBIT_MAIL_ENCRYPTION';

    /** The address mail is sent from. */
    public const string FROM = 'TRILOBIT_MAIL_FROM';

    /**
     * Where compose.yaml's catcher is reached from the machine it runs on. A
     * process inside the same Compose network reaches it as `mailpit` on 1025
     * instead, and says so through its own environment, which wins over .env.
     */
    public const string DEFAULT_HOST = '127.0.0.1';

    /**
     * The port compose.yaml publishes the catcher's SMTP on. Not 25 or 1025,
     * for the reason the database is on 13306: a developer's machine often has
     * something on the obvious one. The two have to move together, and
     * Trilobit\Tests\Unit\Core\Mail\MailersTest reads compose.yaml to hold them.
     * The catcher's inbox, which nothing here sends to, is published on 18200
     * (TRILOBIT_MAILPIT_PORT) - above the 18000 and up that further checkouts
     * of this repository serve their pages on.
     */
    public const int DEFAULT_PORT = 11025;

    /**
     * Where mail is from when the deployment does not say. An SMTP server that
     * delivers anywhere real will refuse it, loudly - which is the right way
     * for a forgotten setting to show itself.
     */
    public const string DEFAULT_FROM = 'trilobit@localhost';

    private const string SMTP = 'smtp';

    private const string FILE = 'file';

    /** The two the SMTP client knows; anything else it would read as none. */
    private const array ENCRYPTIONS = ['ssl', 'tls'];

    public function __construct(
        private Environment $environment,
        private Mode $mode,
        /** Where a working copy writes its mail when it is told to write it to files. */
        private string $mailDirectory,
    ) {}

    /** @throws LogicException when the environment names a way that cannot be right here */
    public function fromEnvironment(): Mailer
    {
        $transport = $this->environment->value(self::TRANSPORT, self::SMTP);

        return match ($transport) {
            self::SMTP => $this->smtp(),
            self::FILE => $this->files(),
            default => throw new LogicException(sprintf(
                '%s says "%s", and mail leaves this application in one of two ways: "%s", to the server the other '
                    . 'settings name, or "%s", written to %s on a working copy. Leaving it empty is "%s".',
                self::TRANSPORT,
                $transport,
                self::SMTP,
                self::FILE,
                $this->mailDirectory,
                self::SMTP,
            )),
        };
    }

    public function from(): string
    {
        return $this->environment->value(self::FROM, self::DEFAULT_FROM);
    }

    private function smtp(): SmtpMailer
    {
        $port = $this->environment->value(self::PORT, (string) self::DEFAULT_PORT);
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new LogicException(sprintf('%s says "%s", and a port is a number from 1 to 65535.', self::PORT, $port));
        }

        $encryption = $this->environment->value(self::ENCRYPTION);
        if ($encryption !== '' && !in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new LogicException(sprintf(
                '%s says "%s", and the SMTP client knows %s, or nothing for none. Anything else would reach it as '
                    . 'no encryption at all, and the password would go in the clear.',
                self::ENCRYPTION,
                $encryption,
                implode(' and ', self::ENCRYPTIONS),
            ));
        }

        return new SmtpMailer(
            $this->environment->value(self::HOST, self::DEFAULT_HOST),
            $this->environment->value(self::USER),
            $this->environment->value('TRILOBIT_MAIL_PASSWORD'),
            (int) $port,
            $encryption === '' ? null : $encryption,
        );
    }

    private function files(): FileMailer
    {
        if (!$this->mode->mayAlterData()) {
            throw new LogicException(sprintf(
                '%s says "%s", and this deployment is %s. Mail written to a file reaches nobody, while whoever sent '
                    . 'it is told it went, so only a working copy - %s=%s - may keep its mail that way. Name an SMTP '
                    . 'server instead.',
                self::TRANSPORT,
                self::FILE,
                $this->mode->value,
                Mode::VARIABLE,
                Mode::Dev->value,
            ));
        }

        return new FileMailer($this->mailDirectory);
    }
}
