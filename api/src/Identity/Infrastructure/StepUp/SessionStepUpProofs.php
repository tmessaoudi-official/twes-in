<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\StepUp;

use App\Identity\Application\StepUp\StepUpProofs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * The proof lives in the server-side session, keyed on the account, so the browser can neither claim one nor carry it
 * to another sign-in: a login migrates the session and a logout invalidates it.
 */
final readonly class SessionStepUpProofs implements StepUpProofs
{
    private const string KEY = '_step_up_proof';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function remember(Uuid $userId, \DateTimeImmutable $at): void
    {
        $this->requestStack->getSession()->set(self::KEY, ['user' => $userId->toRfc4122(), 'at' => $at->getTimestamp()]);
    }

    public function lastFor(Uuid $userId): ?\DateTimeImmutable
    {
        $proof = $this->requestStack->getSession()->get(self::KEY);

        if (!\is_array($proof) || $userId->toRfc4122() !== ($proof['user'] ?? null) || !\is_int($proof['at'] ?? null)) {
            return null;
        }

        return new \DateTimeImmutable('@'.$proof['at']);
    }
}
