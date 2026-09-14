<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Mail;

use Nette\Mail\FileMailer;
use Nette\Mail\Message;
use Nette\Mail\SmtpMailer;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Config\Mode;
use Trilobit\Core\Mail\Mailers;

/**
 * Which way mail leaves this deployment, read from its environment.
 *
 * Every case here is about the one failure mail has that nothing else in the
 * application has: a message that went nowhere looks exactly like a message
 * that was sent. So a setting that cannot be right is refused out loud rather
 * than read as the nearest thing it might have meant, and a transport that
 * writes to a file is refused wherever somebody could believe the mail was
 * delivered.
 */
#[CoversClass(Mailers::class)]
final class MailersTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '') {
            FileSystem::delete($this->directory);
            $this->directory = '';
        }
    }

    /**
     * Nothing said is SMTP to the catcher compose.yaml starts, on the port it
     * publishes - the same way an empty database setting is the database
     * compose.yaml starts. Somewhere with no catcher that is a refused
     * connection the person sending sees, rather than a silence.
     */
    public function testNothingSaidIsSmtpToTheCatcherComposeStarts(): void
    {
        $mailer = $this->mailers([], Mode::Prod)->fromEnvironment();

        self::assertInstanceOf(SmtpMailer::class, $mailer);
        self::assertSame('127.0.0.1', $this->settingOf($mailer, 'host'));
        self::assertSame(Mailers::DEFAULT_PORT, $this->settingOf($mailer, 'port'));
        self::assertNull($this->settingOf($mailer, 'encryption'));
    }

    public function testASmtpServerIsTakenAsItIsNamed(): void
    {
        $mailer = $this->mailers([
            Mailers::TRANSPORT => 'smtp',
            Mailers::HOST => 'smtp.example.com',
            Mailers::PORT => '465',
            Mailers::USER => 'mailer@example.com',
            Mailers::ENCRYPTION => 'ssl',
        ], Mode::Prod)->fromEnvironment();

        self::assertInstanceOf(SmtpMailer::class, $mailer);
        self::assertSame('smtp.example.com', $this->settingOf($mailer, 'host'));
        self::assertSame(465, $this->settingOf($mailer, 'port'));
        self::assertSame('mailer@example.com', $this->settingOf($mailer, 'username'));
        self::assertSame('ssl', $this->settingOf($mailer, 'encryption'));
    }

    /**
     * A working copy may keep its mail as files, and they are readable: that is
     * what the browser suite reads an invitation out of.
     */
    public function testAWorkingCopyMayWriteItsMailToFiles(): void
    {
        $this->directory = sys_get_temp_dir() . '/trilobit-mailers-' . bin2hex(random_bytes(6));

        $mailer = $this->mailers([Mailers::TRANSPORT => 'file'], Mode::Dev)->fromEnvironment();
        self::assertInstanceOf(FileMailer::class, $mailer);

        $mailer->send(new Message()
            ->setFrom('trilobit@example.com')
            ->addTo('somebody@example.com')
            ->setSubject('Written, not sent')
            ->setBody('Nobody receives this.'));

        $written = glob($this->directory . '/*.eml');
        self::assertIsArray($written);
        self::assertCount(1, $written);
        self::assertStringContainsString('Written, not sent', FileSystem::read($written[0]));
    }

    /**
     * Anywhere but a working copy, a message written to a file is a message
     * nobody receives while the screen says it went. That is the silence this
     * refusal exists against, so it stops the application instead.
     */
    #[DataProvider('everyModeButAWorkingCopy')]
    public function testMailWrittenToFilesIsRefusedAnywhereButAWorkingCopy(Mode $mode): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Mailers::TRANSPORT);

        $this->mailers([Mailers::TRANSPORT => 'file'], $mode)->fromEnvironment();
    }

    /** @return iterable<string, array{Mode}> */
    public static function everyModeButAWorkingCopy(): iterable
    {
        yield 'staging' => [Mode::Staging];
        yield 'prod' => [Mode::Prod];
    }

    /**
     * A transport nobody wrote is not read as the nearest one that exists: a
     * misspelt `file` taken as SMTP would send real mail from a machine whose
     * owner thought it could not.
     */
    public function testATransportThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('files');

        $this->mailers([Mailers::TRANSPORT => 'files'], Mode::Dev)->fromEnvironment();
    }

    public function testAPortThatIsNotANumberIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Mailers::PORT);

        $this->mailers([Mailers::PORT => 'twenty-five'], Mode::Prod)->fromEnvironment();
    }

    /**
     * Encryption is one of the two names the SMTP client knows. Anything else
     * would reach it and be read as no encryption at all - credentials sent in
     * the clear because of a spelling.
     */
    public function testEncryptionTheClientDoesNotKnowIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('starttls');

        $this->mailers([Mailers::ENCRYPTION => 'starttls'], Mode::Prod)->fromEnvironment();
    }

    public function testMailIsFromTheAddressTheDeploymentNames(): void
    {
        self::assertSame(Mailers::DEFAULT_FROM, $this->mailers([], Mode::Prod)->from());
        self::assertSame(
            'office@example.com',
            $this->mailers([Mailers::FROM => 'office@example.com'], Mode::Prod)->from(),
        );
    }

    /**
     * The default port and the one compose.yaml publishes the catcher on have
     * to move together, the way the database's do. Read out of the file rather
     * than written here a second time, so that changing one of them alone
     * fails here instead of leaving a working copy sending into nothing.
     */
    public function testTheDefaultPortIsTheOneComposePublishesTheCatcherOn(): void
    {
        $compose = FileSystem::read(dirname(__DIR__, 4) . '/compose.yaml');

        self::assertMatchesRegularExpression(
            '/\$\{' . Mailers::PORT . ':-' . Mailers::DEFAULT_PORT . '\}:1025/',
            $compose,
            'compose.yaml publishes the catcher on another port than the one mail is sent to by default',
        );
    }

    /** @param array<string, string> $values */
    private function mailers(array $values, Mode $mode): Mailers
    {
        return new Mailers(
            Environment::fromValues($values),
            $mode,
            $this->directory === '' ? sys_get_temp_dir() . '/trilobit-mailers-unused' : $this->directory,
        );
    }

    /**
     * One setting of the SMTP client, read off it. The client keeps them
     * private and offers no way to ask, and what is being claimed is exactly
     * what it was handed - so reflection, over asking this class to repeat
     * what it passed on.
     */
    private function settingOf(SmtpMailer $mailer, string $name): mixed
    {
        return new \ReflectionProperty(SmtpMailer::class, $name)->getValue($mailer);
    }
}
