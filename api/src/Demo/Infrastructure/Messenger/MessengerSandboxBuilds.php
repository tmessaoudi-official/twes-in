<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Messenger;

use App\Demo\Application\SandboxBuilds;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final readonly class MessengerSandboxBuilds implements SandboxBuilds
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function queue(Uuid $sandboxId): void
    {
        $this->bus->dispatch(new SandboxToBuild($sandboxId->toRfc4122()));
    }
}
