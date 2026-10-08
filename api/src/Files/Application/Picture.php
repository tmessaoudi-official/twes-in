<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

/** What a raster picture is, read from its bytes: its type and its size as it is seen, upright. */
final readonly class Picture
{
    public function __construct(public string $mime, public int $width, public int $height)
    {
    }

    public function pixels(): int
    {
        return $this->width * $this->height;
    }
}
