<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Identity;

use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Identity\Application\Account\DemoAccounts;
use Symfony\Component\Uid\Uuid;

/** A demo account is a sandbox's owner, from the moment its build opens it until the sandbox is taken away. */
final readonly class SandboxOwners implements DemoAccounts
{
    public function __construct(private DemoSandboxRepository $sandboxes)
    {
    }

    public function isDemo(Uuid $userId): bool
    {
        return $this->sandboxes->ownsOne($userId);
    }

    public function all(): array
    {
        return $this->sandboxes->owners();
    }
}
