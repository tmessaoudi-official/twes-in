<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support\Stateful;

use Symfony\Contracts\Service\ResetInterface;

/** The same service, forgetting what a request set once it is over: StatefulServicesTest finds it and lets it be. */
final class ForgetsTheLastCompany implements ResetInterface
{
    private ?string $company = null;

    public function actFor(string $company): void
    {
        $this->company = $company;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    public function reset(): void
    {
        $this->company = null;
    }
}
