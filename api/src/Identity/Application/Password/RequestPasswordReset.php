<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An address asks for a way back into its account, and a mail answers. An address with no account, or a deactivated
 * one, is sent nothing and the caller answers the same either way, so the response never says which it was: only whoever
 * reads that inbox learns anything. Asking again replaces the open link.
 */
final readonly class RequestPasswordReset
{
    public const string REQUESTED = 'auth.password_reset_requested';

    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $resets,
        private PasswordResetMailer $mailer,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.password_reset.link_url')]
        private string $linkUrlTemplate,
        #[Autowire(param: 'app.password_reset.valid_for')]
        private string $validFor,
    ) {
    }

    public function handle(Email $email, ?string $locale): void
    {
        $user = $this->users->ofEmail($email);
        if (null === $user || !$user->isActive()) {
            return;
        }

        $now = $this->clock->now();
        $token = PasswordResetToken::generate();
        $reset = new PasswordReset($user, $token, $now, new \DateInterval($this->validFor));
        $this->transactions->run(function () use ($user, $reset): void {
            foreach ($this->resets->openFor($user) as $open) {
                $this->resets->remove($open);
            }
            $this->resets->save($reset);
            $this->audit->record(new AuditEntry('user', $user->getId(), self::REQUESTED, $user->getId()));
        });

        $this->mailer->send(new PasswordResetMail(
            $email->value,
            str_replace('{token}', $token->raw, $this->linkUrlTemplate),
            \in_array($locale, ['fr', 'en'], true) ? $locale : $user->getLocale(),
            intdiv($reset->getExpiresAt()->getTimestamp() - $now->getTimestamp(), 60),
        ));
    }
}
