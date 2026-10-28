<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Mail;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SupportSettings;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Application\Approval\CompanyApprovalMailer;
use App\Tenancy\Application\Approval\CompanyApprovedMail;
use App\Tenancy\Application\Approval\CompanyDecisionMail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells an owner an operator's decision on their company, over the configured DSN, rendered in the owner's language. A
 * refusal or a suspension says the reason given and the ways to reach the operators the platform names, as the page
 * the owner then signs in to does.
 */
final readonly class SymfonyCompanyApprovalMailer implements CompanyApprovalMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private ReadSetting $settings,
        #[Autowire(param: 'app.mail.sender')]
        private string $sender,
        #[Autowire(param: 'app.product_name')]
        private string $productName,
    ) {
    }

    public function approved(CompanyApprovedMail $mail): void
    {
        $this->opened('company_approved', $mail);
    }

    public function restored(CompanyApprovedMail $mail): void
    {
        $this->opened('company_restored', $mail);
    }

    public function suspended(CompanyDecisionMail $mail): void
    {
        $this->closed('company_suspended', $mail);
    }

    public function refused(CompanyDecisionMail $mail): void
    {
        $this->closed('company_refused', $mail);
    }

    private function opened(string $kind, CompanyApprovedMail $mail): void
    {
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans($kind.'.subject', ['%company%' => $mail->companyName, '%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/'.$kind.'.html.twig')
                ->locale($mail->locale)
                ->context([
                    'companyName' => $mail->companyName,
                    'loginUrl' => $mail->loginUrl,
                    'productName' => $this->productName,
                ]),
        );
    }

    private function closed(string $kind, CompanyDecisionMail $mail): void
    {
        $support = $this->support();
        $message = (new TemplatedEmail())->from($this->sender);
        // The sender is nobody's inbox: a reply goes where the operators read their mail, when they named one.
        if (isset($support['email'])) {
            $message->replyTo($support['email']);
        }
        $this->mailer->send(
            $message
                ->to($mail->to)
                ->subject($this->translator->trans($kind.'.subject', ['%company%' => $mail->companyName, '%product%' => $this->productName], 'emails', $mail->locale))
                ->htmlTemplate('email/company_closed_by_operator.html.twig')
                ->locale($mail->locale)
                ->context([
                    'kind' => $kind,
                    'companyName' => $mail->companyName,
                    'reason' => $mail->reason,
                    'productName' => $this->productName,
                    'support' => $support,
                ]),
        );
    }

    /** @return array<string, string> each way to reach the operators the platform names, by channel */
    private function support(): array
    {
        $channels = [];
        foreach (['phone' => SupportSettings::PHONE, 'whatsapp' => SupportSettings::WHATSAPP, 'email' => SupportSettings::EMAIL] as $channel => $key) {
            $value = $this->settings->value(new SettingContext(), $key);
            if (\is_string($value) && '' !== TypedText::trimmed($value)) {
                $channels[$channel] = TypedText::trimmed($value);
            }
        }

        return $channels;
    }
}
