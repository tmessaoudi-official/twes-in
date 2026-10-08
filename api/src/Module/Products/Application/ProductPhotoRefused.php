<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/**
 * A photo that is not kept: the reason as a stable code and its parameters beside the English message, for a screen to
 * translate. `empty`, `not_a_picture`, `too_large` (maxBytes), `too_many_pixels` (maxMegapixels), `too_many_photos`
 * (max).
 */
final class ProductPhotoRefused extends \DomainException
{
    public const string EMPTY = 'empty';
    public const string NOT_A_PICTURE = 'not_a_picture';
    public const string TOO_LARGE = 'too_large';
    public const string TOO_MANY_PIXELS = 'too_many_pixels';
    public const string TOO_MANY_PHOTOS = 'too_many_photos';
    public const array REASONS = [self::EMPTY, self::NOT_A_PICTURE, self::TOO_LARGE, self::TOO_MANY_PIXELS, self::TOO_MANY_PHOTOS];

    /** @param array<string, int> $params */
    public function __construct(string $message, public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
