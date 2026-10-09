<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Mail;

use App\Inbox\Application\NotificationMail;
use App\Inbox\Application\NotificationMailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A notification as a mail, in its reader's language: the kind's short name (and its company) as the subject, the
 * sentence the bell says as the body. Its stop link, also given as List-Unsubscribe, opens the page whose button turns
 * that kind's mail off; it decides nothing by itself, since mail clients and scanners open links on their own.
 */
#[AsAlias(NotificationMailer::class)]
final readonly class SymfonyNotificationMailer implements NotificationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        #[Autowire(param: 'app.mail.sender')]
        private string $sender,
        #[Autowire(param: 'app.product_name')]
        private string $productName,
        #[Autowire(param: 'app.notification.open_url')]
        private string $openUrl,
        #[Autowire(param: 'app.notification.stop_url')]
        private string $stopUrl,
    ) {
    }

    public function send(NotificationMail $mail): void
    {
        $key = str_replace('.', '_', $mail->type);
        $kind = $this->translator->trans("notification.kinds.$key", [], 'emails', $mail->locale);
        $stopUrl = str_replace('{token}', rawurlencode($mail->stopToken), $this->stopUrl);
        $email = (new TemplatedEmail())
            ->from($this->sender)
            ->to($mail->to)
            ->subject(null === $mail->companyName ? $kind : $kind.' · '.$mail->companyName)
            ->htmlTemplate('email/notification.html.twig')
            ->locale($mail->locale)
            ->context([
                'kind' => $kind,
                'sentence' => $this->translator->trans("notification.types.$key", self::parameters($mail->payload), 'emails', $mail->locale),
                'companyName' => $mail->companyName,
                'openUrl' => $this->openUrl,
                'stopUrl' => $stopUrl,
                'productName' => $this->productName,
                'locale' => $mail->locale,
            ]);
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$stopUrl.'>');

        $this->mailer->send($email);
    }

    /**
     * @param array<string, scalar|null> $payload
     *
     * @return array<string, string> each value under its %name%, as the sentence reads it
     */
    private static function parameters(array $payload): array
    {
        $parameters = [];
        foreach ($payload as $name => $value) {
            $parameters['%'.$name.'%'] = match (true) {
                null === $value => '',
                \is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };
        }

        return $parameters;
    }
}
