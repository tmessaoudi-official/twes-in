<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Application\Session\CompanyProofs;
use Symfony\Component\Uid\Uuid;

final class InMemoryCompanyProofs implements CompanyProofs
{
    /** @var array<string, array{proved: \DateTimeImmutable, used: \DateTimeImmutable}> */
    private array $proofs = [];

    public function proved(Uuid $companyId, \DateTimeImmutable $at): void
    {
        $this->proofs[$companyId->toRfc4122()] = ['proved' => $at, 'used' => $at];
    }

    public function of(Uuid $companyId): ?array
    {
        return $this->proofs[$companyId->toRfc4122()] ?? null;
    }

    public function used(Uuid $companyId, \DateTimeImmutable $at): void
    {
        if (isset($this->proofs[$companyId->toRfc4122()])) {
            $this->proofs[$companyId->toRfc4122()]['used'] = $at;
        }
    }

    public function forget(Uuid $companyId): void
    {
        unset($this->proofs[$companyId->toRfc4122()]);
    }

    public function forgetAll(): void
    {
        $this->proofs = [];
    }
}
