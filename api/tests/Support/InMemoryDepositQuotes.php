<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Application\DepositQuotes;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/** The quotes deposits were drawn from, by company and id, each with the net it comes to; the hold is the caller's transaction's. */
final class InMemoryDepositQuotes implements DepositQuotes
{
    /** @var array<string, string> net by company and quote id */
    private array $nets = [];

    public function quote(Company $company, Uuid $quoteId, string $net): void
    {
        $this->nets[$company->getId()->toRfc4122().'|'.$quoteId->toRfc4122()] = $net;
    }

    public function heldNet(Company $company, Uuid $quoteId): ?string
    {
        return $this->nets[$company->getId()->toRfc4122().'|'.$quoteId->toRfc4122()] ?? null;
    }
}
