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
    private const string FAILED = '_step_up_failed';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function remember(Uuid $userId, \DateTimeImmutable $at, bool $withSecondFactor): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::KEY, ['user' => $userId->toRfc4122(), 'at' => $at->getTimestamp(), 'factor' => $withSecondFactor]);
        $session->remove(self::FAILED);
    }

    public function failed(Uuid $userId): int
    {
        $session = $this->requestStack->getSession();
        $failed = $session->get(self::FAILED);
        $count = (\is_array($failed) && $userId->toRfc4122() === ($failed['user'] ?? null) && \is_int($failed['count'] ?? null) ? $failed['count'] : 0) + 1;
        $session->set(self::FAILED, ['user' => $userId->toRfc4122(), 'count' => $count]);

        return $count;
    }

    public function lastFor(Uuid $userId, bool $withSecondFactor): ?\DateTimeImmutable
    {
        $proof = $this->requestStack->getSession()->get(self::KEY);

        if (!\is_array($proof) || $userId->toRfc4122() !== ($proof['user'] ?? null) || !\is_int($proof['at'] ?? null)) {
            return null;
        }
        // A session the release before wrote holds no 'factor': it was the password alone as far as anyone can tell.
        if ($withSecondFactor && true !== ($proof['factor'] ?? null)) {
            return null;
        }

        return new \DateTimeImmutable('@'.$proof['at']);
    }

    public function forget(): void
    {
        $this->requestStack->getSession()->remove(self::KEY);
    }
}
