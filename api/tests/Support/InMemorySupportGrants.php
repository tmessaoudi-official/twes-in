<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Domain\SupportGrant;
use App\Tenancy\Domain\SupportGrantRepository;
use Symfony\Component\Uid\Uuid;

final class InMemorySupportGrants implements SupportGrantRepository
{
    /** @var array<string, SupportGrant> */
    private array $grants = [];

    public function liveIn(Uuid $companyId, \DateTimeImmutable $now): ?SupportGrant
    {
        foreach ($this->grants as $grant) {
            if ($grant->getCompany()->getId()->equals($companyId) && $grant->isLiveAt($now)) {
                return $grant;
            }
        }

        return null;
    }

    public function liveAt(\DateTimeImmutable $now, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->grants, static fn (SupportGrant $grant): bool => $grant->isLiveAt($now))), 0, $limit);
    }

    public function save(SupportGrant $grant): void
    {
        $this->grants[$grant->getId()->toRfc4122()] = $grant;
    }
}
