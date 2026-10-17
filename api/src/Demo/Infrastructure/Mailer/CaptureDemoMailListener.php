<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Mailer;

use App\Demo\Application\CaptureDemoMail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Every mail the application sends passes here on its way to the transport: one to a sandbox's owner is kept for
 * « Courriers de la démo » and rejected, so it never leaves, whichever mailer wrote it.
 *
 * It runs after Twig renders the body (Symfony's own listener, at 0) and before the mailer's logger (-255). The event a
 * queued mail raises first is passed over: the transport raises it again for the same mail, rendered.
 */
#[AsEventListener(priority: -100)]
final readonly class CaptureDemoMailListener
{
    public function __construct(private CaptureDemoMail $capture)
    {
    }

    public function __invoke(MessageEvent $event): void
    {
        if ($event->isQueued()) {
            return;
        }
        $recipients = array_values(array_map(static fn (Address $address): string => $address->getAddress(), $event->getEnvelope()->getRecipients()));
        $message = $event->getMessage();
        $subject = $message instanceof Email ? ($message->getSubject() ?? '') : '';
        if ($this->capture->handle($recipients, $subject, $message instanceof Email ? self::html($message) : '')) {
            $event->reject();
        }
    }

    /** The mail as its reader would see it: its HTML part, else its text part shown as written. */
    private static function html(Email $message): string
    {
        $html = self::read($message->getHtmlBody());
        if (null !== $html) {
            return $html;
        }

        return '<pre>'.htmlspecialchars(self::read($message->getTextBody()) ?? '', \ENT_QUOTES | \ENT_SUBSTITUTE).'</pre>';
    }

    /** @param resource|string|null $body */
    private static function read(mixed $body): ?string
    {
        if (null === $body || \is_string($body)) {
            return $body;
        }
        $read = stream_get_contents($body);

        return false === $read ? throw new \RuntimeException('A mail body could not be read.') : $read;
    }
}
