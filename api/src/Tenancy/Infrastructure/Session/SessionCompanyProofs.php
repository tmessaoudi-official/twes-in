<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Session;

use App\Tenancy\Application\Session\CompanyProofs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * In the server-side session, so the browser can neither claim a company proved nor carry the proof to another
 * sign-in: a login migrates the session and a logout invalidates it.
 */
final readonly class SessionCompanyProofs implements CompanyProofs
{
    private const string KEY = '_company_proofs';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function proved(Uuid $companyId, \DateTimeImmutable $at): void
    {
        $proofs = $this->all();
        $proofs[$companyId->toRfc4122()] = ['proved' => $at->getTimestamp(), 'used' => $at->getTimestamp()];
        $this->session()?->set(self::KEY, $proofs);
    }

    public function of(Uuid $companyId): ?array
    {
        $proof = $this->all()[$companyId->toRfc4122()] ?? null;

        return null === $proof ? null : ['proved' => new \DateTimeImmutable('@'.$proof['proved']), 'used' => new \DateTimeImmutable('@'.$proof['used'])];
    }

    public function used(Uuid $companyId, \DateTimeImmutable $at): void
    {
        $proofs = $this->all();
        if (!isset($proofs[$companyId->toRfc4122()])) {
            return;
        }
        $proofs[$companyId->toRfc4122()]['used'] = $at->getTimestamp();
        $this->session()?->set(self::KEY, $proofs);
    }

    public function forget(Uuid $companyId): void
    {
        $proofs = $this->all();
        unset($proofs[$companyId->toRfc4122()]);
        $this->session()?->set(self::KEY, $proofs);
    }

    public function forgetAll(): void
    {
        $this->session()?->remove(self::KEY);
    }

    /**
     * A request with no session has no sign-in to hold a proof for: a console command or a worker acting for a company
     * proves nothing, so a member of several companies is refused there rather than let in.
     */
    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }

    /** @return array<string, array{proved: int, used: int}> */
    private function all(): array
    {
        $stored = $this->session()?->get(self::KEY);
        if (!\is_array($stored)) {
            return [];
        }
        $proofs = [];
        foreach ($stored as $companyId => $proof) {
            if (\is_string($companyId) && \is_array($proof) && \is_int($proof['proved'] ?? null) && \is_int($proof['used'] ?? null)) {
                $proofs[$companyId] = ['proved' => $proof['proved'], 'used' => $proof['used']];
            }
        }

        return $proofs;
    }
}
