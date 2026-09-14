<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Mail;

use Nette\Mail\Mailer;
use Nette\Mail\Message;
use Nette\Mail\SendException;

/**
 * The mailer every build a test makes is given: it keeps what it is handed and
 * sends nothing anywhere.
 *
 * Every test build gets it rather than the suites that happen to send mail,
 * because "remember to replace the mailer" includes the test nobody has written
 * yet, and the first one that forgets would be sending real mail from a
 * developer's machine - or, with nothing listening, failing for a reason that
 * has nothing to do with what it tests. See Trilobit\Tests\Boot.
 *
 * It can also be told to refuse, which is how a suite shows what a person sees
 * when mail cannot be sent: the refusal is the one the real clients raise.
 */
final class CapturingMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];

    /** The sentence the next sends are refused with, or null while they go through. */
    public ?string $refusing = null;

    public function send(Message $mail): void
    {
        if ($this->refusing !== null) {
            throw new SendException($this->refusing);
        }

        $this->sent[] = $mail;
    }

    /** @return list<Message> what was sent to $address, oldest first */
    public function sentTo(string $address): array
    {
        return array_values(array_filter(
            $this->sent,
            static function (Message $message) use ($address): bool {
                $to = $message->getHeader('To');

                return is_array($to) && array_key_exists($address, $to);
            },
        ));
    }
}
