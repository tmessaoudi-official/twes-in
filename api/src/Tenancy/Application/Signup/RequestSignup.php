<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

use App\Identity\Domain\Email;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\Signup;
use App\Tenancy\Domain\SignupRepository;
use App\Tenancy\Domain\SignupToken;
use Psr\Clock\ClockInterface;

/**
 * The first step of signup: an address asks, and a mail answers. An address with no account is sent a link; one that
 * has an account is told so and offered the sign-in page instead. The caller answers both the same way, so the response
 * never says which it was: only whoever reads that inbox learns it.
 */
final readonly class RequestSignup
{
    public function __construct(
        private SignupPolicy $policy,
        private UserRepository $users,
        private SignupRepository $signups,
        private SignupMailer $mailer,
        private ClockInterface $clock,
        private string $linkUrlTemplate,
        private string $loginUrl,
        private string $validFor,
    ) {
    }

    /** @throws SignupClosed */
    public function handle(Email $email, ?string $locale): void
    {
        if (!$this->policy->isOpen()) {
            throw new SignupClosed('Signup is closed.');
        }
        $locale = SignupPolicy::locale($locale);

        if (null !== $this->users->ofEmail($email)) {
            $this->mailer->accountExists(new AccountExistsMail($email->value, $this->loginUrl, $locale));

            return;
        }

        // Asking again replaces the open link: two live tokens for one address is one more than anybody needs.
        $open = $this->signups->openFor($email);
        if (null !== $open) {
            $this->signups->remove($open);
        }

        $now = $this->clock->now();
        $token = SignupToken::generate();
        $signup = new Signup($email, $locale, $token, $now, new \DateInterval($this->validFor));
        $this->signups->save($signup);

        $this->mailer->link(new SignupLinkMail(
            $email->value,
            str_replace('{token}', $token->raw, $this->linkUrlTemplate),
            $locale,
            intdiv($signup->getExpiresAt()->getTimestamp() - $now->getTimestamp(), 3600),
        ));
    }
}
