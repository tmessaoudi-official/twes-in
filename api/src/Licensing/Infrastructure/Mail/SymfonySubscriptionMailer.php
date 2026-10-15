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
use App\Shared\Infrastructure\Mail\MailFigures;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The subscription mails, over the configured DSN, each rendered in its reader's language, its amount and its days
 * written as that reader writes them, the amount at its currency's decimals. Neither carries a link that
 * decides anything: a mail client opens links on its own, so the operator's mail leads to the platform page and the
 * decision is made there, signed in.
 */
final readonly class SymfonySubscriptionMailer implements SubscriptionMailer
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

    public function paymentDeclared(PaymentDeclaredMail $mail): void
    {
        $amount = $this->figures->amount($mail->amount, $mail->scale, $mail->locale, $mail->numberFormat);
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('payment_declared.subject', ['%company%' => $mail->companyName, '%amount%' => $amount, '%currency%' => $mail->currency], 'emails', $mail->locale))
                ->htmlTemplate('email/payment_declared.html.twig')
                ->locale($mail->locale)
                ->context([
                    'companyName' => $mail->companyName,
                    'amount' => $amount,
                    'currency' => $mail->currency,
                    'method' => $mail->method,
                    'paidOn' => $this->figures->day($mail->paidOn, $mail->locale, $mail->dateFormat),
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
                    'amount' => $this->figures->amount($mail->amount, $mail->scale, $mail->locale, $mail->numberFormat),
                    'currency' => $mail->currency,
                    'paidThrough' => null === $mail->paidThrough ? null : $this->figures->day($mail->paidThrough, $mail->locale, $mail->dateFormat),
                    'note' => $mail->note,
                    'loginUrl' => $mail->loginUrl,
                    'productName' => $this->productName,
                ]),
        );
    }
}
