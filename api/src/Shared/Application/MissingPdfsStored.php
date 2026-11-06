<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * What a pass storing the PDFs issued documents missed came to: how many it stored, and each one it could not store,
 * by its number, with why. A document that fails is named and passed over, so it never holds back the ones after it.
 */
final readonly class MissingPdfsStored
{
    /** @param array<string, string> $failed why, by document number */
    public function __construct(public int $stored, public array $failed)
    {
    }
}
