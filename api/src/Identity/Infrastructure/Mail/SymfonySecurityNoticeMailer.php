<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNoticeMail;
use App\Identity\Application\Security\SecurityNoticeMailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SymfonySecurityNoticeMailer implements SecurityNoticeMailer
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

    public function send(SecurityNoticeMail $mail): void
    {
        $change = $mail->change->value;
        $heading = $this->translator->trans("security_notice.$change.heading", [], 'emails', $mail->locale);
        $this->mailer->send(
            (new TemplatedEmail())
                ->from($this->sender)
                ->to($mail->to)
                ->subject($this->translator->trans('security_notice.subject', ['%product%' => $this->productName, '%change%' => $heading], 'emails', $mail->locale))
                ->htmlTemplate('email/security_notice.html.twig')
                ->locale($mail->locale)
                ->context([
                    'change' => $change,
                    'passkeyName' => \in_array($mail->change, [SecurityChange::PasskeyAdded, SecurityChange::PasskeyRemoved], true) ? $mail->passkeyName : null,
                    // Replaced from the server, the person is told what to do next, not asked whether it was them.
                    'fromServer' => SecurityChange::FactorsReplaced === $mail->change,
                    'productName' => $this->productName,
                ]),
        );
    }
}
