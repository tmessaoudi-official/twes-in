<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\Password\PasswordResetMail;
use App\Identity\Application\Password\PasswordResetMailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Sends the reset mail over the configured DSN, rendered from a Twig template in the language asked for. */
final readonly class SymfonyPasswordResetMailer implements PasswordResetMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        #[Autowire(param: 'app.mail.sender')]
        private string $sender,
        #[Autowire(param: 'app.product_name')]
        private string $productName,
    ) {
    }

    public function send(PasswordResetMail $mail): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('password_reset.subject', ['%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/password_reset.html.twig')
                ->locale($mail->locale)
                ->context([
                    'resetUrl' => $mail->resetUrl,
                    'validForMinutes' => $mail->validForMinutes,
                    'productName' => $this->productName,
                ]),
        );
    }
}
