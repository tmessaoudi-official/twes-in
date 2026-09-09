<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Mail;

use App\Tenancy\Application\Invitation\InvitationMail;
use App\Tenancy\Application\Invitation\InvitationMailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Sends the invitation over the configured DSN, rendered from a Twig template in the company's language. */
final readonly class SymfonyInvitationMailer implements InvitationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private string $sender,
        private string $productName,
    ) {
    }

    public function send(InvitationMail $mail): void
    {
        $subject = $this->translator->trans(
            'invitation.subject',
            ['%company%' => $mail->companyName, '%product%' => $this->productName],
            'emails',
            $mail->locale,
        );

        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($subject)
                ->htmlTemplate('email/invitation.html.twig')
                ->locale($mail->locale)
                ->context([
                    'companyName' => $mail->companyName,
                    'roleName' => $mail->roleName,
                    'invitedByName' => $mail->invitedByName,
                    'acceptUrl' => $mail->acceptUrl,
                    'expiresAt' => $mail->expiresAt,
                    'timezone' => $mail->timezone,
                    'productName' => $this->productName,
                ]),
        );
    }
}
