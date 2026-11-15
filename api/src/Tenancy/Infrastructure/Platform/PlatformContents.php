<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Platform;

/** What app:platform:reset empties in place: every row but the migrations list, and every stored file. */
interface PlatformContents
{
    /** @return array{tables: int, files: int} how many tables and stored files were emptied */
    public function empty(): array;
}
