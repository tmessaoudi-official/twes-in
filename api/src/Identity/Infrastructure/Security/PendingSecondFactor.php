<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * The half-authenticated state between the password and the second factor.
 *
 * It holds a user id and nothing else — no roles, no token, nothing the rest of the application would read
 * as an identity. It lives in the session so it cannot be replayed from another browser, and it expires on
 * its own so an abandoned half-login does not stay open.
 */
final readonly class PendingSecondFactor
{
    private const string KEY = '_mfa_pending_user';
    private const string EXPIRY_KEY = '_mfa_pending_until';

    public function __construct(
        private RequestStack $requestStack,
        private string $ttl,
    ) {
    }

    public function open(Uuid $userId, ?\DateTimeImmutable $now = null): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::KEY, $userId->toRfc4122());
        $session->set(self::EXPIRY_KEY, ($now ?? new \DateTimeImmutable())->add(new \DateInterval($this->ttl))->getTimestamp());
    }

    /** @return Uuid|null the user waiting to finish, or null when there is none or it has expired */
    public function waiting(?\DateTimeImmutable $now = null): ?Uuid
    {
        $session = $this->requestStack->getSession();
        $raw = $session->get(self::KEY);
        $until = $session->get(self::EXPIRY_KEY);

        if (!\is_string($raw) || !\is_int($until)) {
            return null;
        }

        if (($now ?? new \DateTimeImmutable())->getTimestamp() > $until) {
            $this->close();

            return null;
        }

        return Uuid::isValid($raw) ? Uuid::fromString($raw) : null;
    }

    public function close(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::KEY);
        $session->remove(self::EXPIRY_KEY);
    }
}
