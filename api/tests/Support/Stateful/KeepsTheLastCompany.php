<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support\Stateful;

/**
 * A service keeping what one request set, with nothing to forget it: under a worker the next person's request reads
 * it. StatefulServicesTest must find it.
 */
final class KeepsTheLastCompany
{
    private ?string $company = null;

    /** @var list<string> */
    private array $seen = [];

    private static int $calls = 0;

    public function actFor(string $company): void
    {
        $this->company = $company;
        $this->seen[] = $company;
        ++self::$calls;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    /** @return list<string> */
    public function seen(): array
    {
        return $this->seen;
    }
}
