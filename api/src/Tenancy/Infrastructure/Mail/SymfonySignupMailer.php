<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Mail;

use App\Tenancy\Application\Signup\AccountExistsMail;
use App\Tenancy\Application\Signup\SignupLinkMail;
use App\Tenancy\Application\Signup\SignupMailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Sends the two signup mails over the configured DSN, rendered from Twig templates in the language asked for. */
final readonly class SymfonySignupMailer implements SignupMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private string $sender,
        private string $productName,
    ) {
    }

    public function link(SignupLinkMail $mail): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('signup.subject', ['%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/signup_link.html.twig')
                ->locale($mail->locale)
                ->context([
                    'linkUrl' => $mail->linkUrl,
                    'validForHours' => $mail->validForHours,
                    'productName' => $this->productName,
                ]),
        );
    }

    public function accountExists(AccountExistsMail $mail): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('signup.account_exists.subject', ['%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/signup_account_exists.html.twig')
                ->locale($mail->locale)
                ->context([
                    'loginUrl' => $mail->loginUrl,
                    'productName' => $this->productName,
                ]),
        );
    }
}
