<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\CustomerScreen;

use App\Identity\Application\CustomerScreen\CustomerScreenLock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Kept in the session the sign-in cookie names, so every tab of that browser is held, and bound to the account, so a
 * session that changed hands holds nobody else.
 */
final readonly class SessionCustomerScreenLock implements CustomerScreenLock
{
    private const string KEY = '_customer_screen';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function lock(Uuid $userId, Uuid $companyId): void
    {
        $this->requestStack->getSession()->set(self::KEY, ['user' => $userId->toRfc4122(), 'company' => $companyId->toRfc4122()]);
    }

    public function lockedFor(Uuid $userId): ?Uuid
    {
        $session = $this->requestStack->getCurrentRequest()?->hasSession() ? $this->requestStack->getSession() : null;
        $lock = $session?->get(self::KEY);
        if (!\is_array($lock) || $userId->toRfc4122() !== ($lock['user'] ?? null) || !\is_string($lock['company'] ?? null) || !Uuid::isValid($lock['company'])) {
            return null;
        }

        return Uuid::fromString($lock['company']);
    }

    public function release(): void
    {
        $this->requestStack->getSession()->remove(self::KEY);
    }
}
