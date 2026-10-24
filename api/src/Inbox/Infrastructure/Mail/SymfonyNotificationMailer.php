<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Infrastructure\Mail;

use App\Inbox\Application\NotificationKinds;
use App\Inbox\Application\NotificationMail;
use App\Inbox\Application\NotificationMailer;
use App\Shared\Infrastructure\Mail\MailFigures;
use App\Texts\Application\TextsInForce;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A notification as a mail, in its reader's language: the kind's short name (and its company) as the subject, the
 * sentence the bell says as the body, its amounts, quantities, days and moments written as the reader writes them (as a
 * printed document does, from the decimal string and never through a float). Its stop link, also given as List-Unsubscribe, opens the page whose button turns
 * that kind's mail off; it decides nothing by itself, since mail clients and scanners open links on their own.
 */
#[AsAlias(NotificationMailer::class)]
final readonly class SymfonyNotificationMailer implements NotificationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TextsInForce $texts,
        private TranslatorInterface $translator,
        private NotificationKinds $kinds,
        private MailFigures $figures,
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
        // In the wording the company gives its texts, else the installation's.
        $this->texts->for($mail->companyId, function () use ($mail): void {
            $this->write($mail);
        });
    }

    private function write(NotificationMail $mail): void
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
                'sentence' => $this->translator->trans("notification.types.$key", $this->parameters($mail), 'emails', $mail->locale),
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
     * @return array<string, string> each value under its %name%, as the sentence reads it; a thing named by its
     *                               translation key (`label_key`, as the screen reads it) is named in the mail's language as %label%;
     *                               an amount, a quantity, a day or a moment its kind names is written as the reader writes it, an
     *                               amount at the payload's `scale` and kept as sent by an older notice that has none
     */
    private function parameters(NotificationMail $mail): array
    {
        $payload = $mail->payload;
        $kind = $this->kinds->get($mail->type);
        $scale = $payload['scale'] ?? null;
        $parameters = [];
        foreach ($payload as $name => $value) {
            $parameters['%'.$name.'%'] = match (true) {
                null === $value => '',
                \is_bool($value) => $value ? '1' : '0',
                !\is_string($value) || null === $kind => (string) $value,
                \is_int($scale) && \in_array($name, $kind->amounts, true) => $this->figures->amount($value, $scale, $mail->locale, $mail->numberFormat),
                \in_array($name, $kind->quantities, true) => $this->figures->quantity($value, $mail->locale, $mail->numberFormat),
                \in_array($name, $kind->days, true) => $this->figures->day($value, $mail->locale, $mail->dateFormat),
                \in_array($name, $kind->moments, true) => $this->figures->moment($value, $mail->locale, $mail->dateFormat),
                default => $value,
            };
        }

        if (\is_string($payload['label_key'] ?? null)) {
            $parameters['%label%'] = $this->translator->trans($payload['label_key'], [], 'emails', $mail->locale);
        }

        return $parameters;
    }
}
