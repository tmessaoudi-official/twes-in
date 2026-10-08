<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

/**
 * Reads and cuts raster pictures. Only JPEG, PNG and WebP are pictures here: a vector file can carry a script, and
 * what is shown is rendered by a browser engine. A picture's size is read from its header, before any pixel is
 * decoded, so whoever keeps one can refuse a size it cannot afford to decode.
 */
interface Pictures
{
    /** The picture these bytes are, or null when they are not a JPEG, a PNG or a WebP picture. */
    public function inspect(string $bytes): ?Picture;

    /**
     * The picture turned upright and fitted within this many pixels on its longest side, never enlarged, as WebP
     * bytes: a copy that carries no metadata of the original, where a phone writes where a photo was taken.
     */
    public function fitted(string $bytes, int $longestSide): string;
}
