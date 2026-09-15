<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Mail;

use App\Tenancy\Application\Company\CompanyApprovalMailer;
use App\Tenancy\Application\Company\CompanyApprovedMail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Tells an owner their company was approved, over the configured DSN, rendered in the owner's language. */
final readonly class SymfonyCompanyApprovalMailer implements CompanyApprovalMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private string $sender,
        private string $productName,
    ) {
    }

    public function approved(CompanyApprovedMail $mail): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('company_approved.subject', ['%company%' => $mail->companyName, '%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/company_approved.html.twig')
                ->locale($mail->locale)
                ->context([
                    'companyName' => $mail->companyName,
                    'loginUrl' => $mail->loginUrl,
                    'productName' => $this->productName,
                ]),
        );
    }
}
