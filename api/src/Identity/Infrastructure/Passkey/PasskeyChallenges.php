<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Passkey;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * The options of the one passkey ceremony under way in this session, kept server-side so the browser cannot choose the
 * challenge it answers. Taking them removes them whether or not what follows verifies: every challenge answers once.
 */
final readonly class PasskeyChallenges
{
    private const string KEY = '_passkey_ceremony';

    public function __construct(
        private RequestStack $requestStack,
        private string $ttl,
    ) {
    }

    public function remember(string $purpose, Uuid $userId, string $optionsJson, ?\DateTimeImmutable $now = null): void
    {
        $this->requestStack->getSession()->set(self::KEY, [
            'purpose' => $purpose,
            'user' => $userId->toRfc4122(),
            'options' => $optionsJson,
            'until' => ($now ?? new \DateTimeImmutable())->add(new \DateInterval($this->ttl))->getTimestamp(),
        ]);
    }

    /** @return string|null the options, or null when there are none for this purpose and account, or they expired */
    public function take(string $purpose, Uuid $userId, ?\DateTimeImmutable $now = null): ?string
    {
        $session = $this->requestStack->getSession();
        $ceremony = $session->get(self::KEY);
        $session->remove(self::KEY);

        if (!\is_array($ceremony)
            || $purpose !== ($ceremony['purpose'] ?? null)
            || $userId->toRfc4122() !== ($ceremony['user'] ?? null)
            || !\is_int($ceremony['until'] ?? null)
            || ($now ?? new \DateTimeImmutable())->getTimestamp() > $ceremony['until']
            || !\is_string($ceremony['options'] ?? null)
        ) {
            return null;
        }

        return $ceremony['options'];
    }
}
