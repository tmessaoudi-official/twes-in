<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\CurrentCompany;
use Symfony\Component\Uid\Uuid;

final class InMemoryCurrentCompany implements CurrentCompany
{
    public function __construct(private ?Uuid $id = null)
    {
    }

    public function id(): ?Uuid
    {
        return $this->id;
    }

    public function set(?Uuid $companyId): void
    {
        $this->id = $companyId;
    }
}
