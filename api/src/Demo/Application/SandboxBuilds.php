<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use Symfony\Component\Uid\Uuid;

/** Hands a queued sandbox to the worker, stored with the change that queued it. */
interface SandboxBuilds
{
    public function queue(Uuid $sandboxId): void;
}
