<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Whether this sign-in may act in a company yet. An account belonging to two companies or more proves itself once per
 * sign-in for each company it enters, with the proof that company accepts; the company the sign-in opened is proved by
 * the sign-in when its factors are ones that company accepts. A proof holds until sign-out, for as long as it is used
 * within the idle time, and no longer than its lifetime in all. Someone in one company is never asked, and support's
 * read-only way into a company is no membership and is never asked either.
 */
final readonly class CompanyEntry
{
    public function __construct(
        private MembershipRepository $memberships,
        private CompanyProofs $proofs,
        private ClockInterface $clock,
        #[Autowire(param: 'app.company_proof.idle')]
        private string $idle,
        #[Autowire(param: 'app.company_proof.lifetime')]
        private string $lifetime,
    ) {
    }

    /**
     * Whether the account must prove itself before acting in this company, which counts as a use of a proof it holds.
     * Only a member is asked: whoever is not one is refused or let in elsewhere.
     */
    public function awaitsProof(Uuid $userId, Uuid $companyId): bool
    {
        $now = $this->clock->now();
        if ($this->holds($companyId, $now)) {
            return false;
        }
        if (null === $this->memberships->ofUserInCompany($userId, $companyId)) {
            return false;
        }
        if (\count($this->memberships->ofUser($userId, 2)) < 2) {
            // One company: nothing to prove, and kept so the next request asks the database nothing.
            $this->proofs->proved($companyId, $now);

            return false;
        }

        return true;
    }

    /**
     * The member companies this sign-in has not proved yet, which the bell and the live changes leave out.
     *
     * @return list<Uuid>
     */
    public function awaitingProof(Uuid $userId): array
    {
        $memberships = $this->memberships->ofUser($userId, \PHP_INT_MAX);
        if (\count($memberships) < 2) {
            return [];
        }
        $now = $this->clock->now();
        $awaiting = [];
        foreach ($memberships as $membership) {
            $companyId = $membership->getCompany()->getId();
            if (!$this->holds($companyId, $now)) {
                $awaiting[] = $companyId;
            }
        }

        return $awaiting;
    }

    /** A new sign-in starts with nothing proved: a session kept from an earlier one carries none of its proofs. */
    public function signedIn(): void
    {
        $this->proofs->forgetAll();
    }

    public function proved(Uuid $companyId): void
    {
        $this->proofs->proved($companyId, $this->clock->now());
    }

    private function holds(Uuid $companyId, \DateTimeImmutable $now): bool
    {
        $proof = $this->proofs->of($companyId);
        if (null === $proof) {
            return false;
        }
        if ($now > $proof['used']->add(new \DateInterval($this->idle)) || $now > $proof['proved']->add(new \DateInterval($this->lifetime))) {
            $this->proofs->forget($companyId);

            return false;
        }
        // A minute's use is written once, so a burst of requests does not rewrite the session each time.
        if ($now->getTimestamp() - $proof['used']->getTimestamp() >= 60) {
            $this->proofs->used($companyId, $now);
        }

        return true;
    }
}
