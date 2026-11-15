<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Infrastructure\Platform\PlatformContents;

/**
 * What app:platform:reset empties, stood in for in every test: the real emptying runs on the owner's connection,
 * outside a case's transaction, and would empty the suite's own database. It says it was asked, and empties nothing.
 */
final class RecordingPlatformContents implements PlatformContents
{
    public bool $emptied = false;

    public function empty(): array
    {
        $this->emptied = true;

        return ['tables' => 7, 'files' => 3];
    }
}
