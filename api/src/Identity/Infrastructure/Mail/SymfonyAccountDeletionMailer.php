<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\Account\AccountDeletionMail;
use App\Identity\Application\Account\AccountDeletionMailer;
use App\Shared\Infrastructure\Mail\MailFigures;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SymfonyAccountDeletionMailer implements AccountDeletionMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private MailFigures $figures,
        #[Autowire(param: 'app.mail.sender')]
        private string $sender,
        #[Autowire(param: 'app.product_name')]
        private string $productName,
    ) {
    }

    public function send(AccountDeletionMail $mail): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('account_deletion.subject', ['%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/account_deletion.html.twig')
                ->locale($mail->locale)
                ->context([
                    'days' => $mail->days,
                    // A day as the person writes it, in UTC like every moment the API keeps.
                    'finalOn' => $this->figures->day($mail->finalAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'), $mail->locale, $mail->dateFormat),
                    'productName' => $this->productName,
                ]),
        );
    }
}
