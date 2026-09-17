<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\Mail;

use App\Licensing\Application\PaymentDecidedMail;
use App\Licensing\Application\PaymentDeclaredMail;
use App\Licensing\Application\SubscriptionMailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The subscription mails, over the configured DSN, each rendered in its reader's language. Neither carries a link that
 * decides anything: a mail client opens links on its own, so the operator's mail leads to the platform page and the
 * decision is made there, signed in.
 */
final readonly class SymfonySubscriptionMailer implements SubscriptionMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private string $sender,
        private string $productName,
    ) {
    }

    public function paymentDeclared(PaymentDeclaredMail $mail): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('payment_declared.subject', ['%company%' => $mail->companyName, '%amount%' => $mail->amount, '%currency%' => $mail->currency], 'emails', $mail->locale))
                ->htmlTemplate('email/payment_declared.html.twig')
                ->locale($mail->locale)
                ->context([
                    'companyName' => $mail->companyName,
                    'amount' => $mail->amount,
                    'currency' => $mail->currency,
                    'method' => $mail->method,
                    'paidOn' => $mail->paidOn,
                    'reference' => $mail->reference,
                    'platformUrl' => $mail->platformUrl,
                    'productName' => $this->productName,
                ]),
        );
    }

    public function paymentDecided(PaymentDecidedMail $mail): void
    {
        $subject = $mail->confirmed ? 'payment_decided.confirmed_subject' : 'payment_decided.rejected_subject';
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans($subject, ['%company%' => $mail->companyName, '%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/payment_decided.html.twig')
                ->locale($mail->locale)
                ->context([
                    'companyName' => $mail->companyName,
                    'confirmed' => $mail->confirmed,
                    'amount' => $mail->amount,
                    'currency' => $mail->currency,
                    'paidThrough' => $mail->paidThrough,
                    'note' => $mail->note,
                    'loginUrl' => $mail->loginUrl,
                    'productName' => $this->productName,
                ]),
        );
    }
}
