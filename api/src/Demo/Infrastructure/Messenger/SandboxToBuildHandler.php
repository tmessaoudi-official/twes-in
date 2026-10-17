<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Messenger;

use App\Demo\Application\BuildSandbox;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SandboxToBuildHandler
{
    public function __construct(private BuildSandbox $build)
    {
    }

    public function __invoke(SandboxToBuild $message): void
    {
        $this->build->handle(Uuid::fromString($message->sandboxId));
    }
}
