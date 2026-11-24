<?php

declare(strict_types=1);

namespace Trilobit\Core\Mail;

use Nette\Mail\Mailer;
use Nette\Mail\Message;
use Nette\Mail\SendException;
use Trilobit\Core\Domain\User\User;
use Trilobit\Core\Security\PasswordLinks;

/**
 * The message somebody added to a business is sent: who added them where, and
 * the link they set their password with.
 *
 * **Sending may fail, and it fails out loud.** The mailer's own refusal is let
 * through as it is, so that whoever added the person is told on the same
 * screen that the invitation did not go - and can send it again - rather than
 * being told it went. The membership is made before the message is sent and
 * is not undone when it fails: the person was added, and what is missing is
 * one message, which the administration can send again. A setting the
 * application refuses (Trilobit\Core\Mail\Mailers) is louder still: it stops
 * the request, since no invitation could go anywhere.
 *
 * The link is the message's whole point, so it is written on a line of its own
 * where a mail client makes it clickable, and the message says how long it
 * opens for and that it opens once.
 */
final readonly class Invitations
{
    public function __construct(
        private Mailer $mailer,
        private Mailers $mailers,
    ) {}

    /** @throws SendException when the message did not go */
    public function send(User $to, string $business, string $link): void
    {
        $message = new Message()
            ->setFrom($this->mailers->from())
            ->addTo($to->email(), $to->name())
            ->setSubject(sprintf('You have been added to %s', $business))
            ->setBody(sprintf(
                "Hello %s,\n\n"
                    . "you have been added to %s. Set your password with this link:\n\n"
                    . "%s\n\n"
                    . 'It opens once, for %d days. When it has stopped opening, ask whoever added you to send the '
                    . "invitation again.\n\n"
                    . "If you were not expecting this, you can leave it be: nothing happens until the link is opened.\n",
                $to->name(),
                $business,
                $link,
                PasswordLinks::DAYS,
            ));

        $this->mailer->send($message);
    }
}
