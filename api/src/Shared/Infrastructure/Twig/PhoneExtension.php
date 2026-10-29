<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Twig;

use App\Shared\Application\Phones\PhoneNumbers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `value|phone`: a kept phone printed grouped as its country writes it (« +216 71 900 868 »), so a printed document or a
 * mail never shows the E.164 it is kept as; a value kept before the numbering plans is printed as it is.
 */
final class PhoneExtension extends AbstractExtension
{
    public function __construct(private readonly PhoneNumbers $phones)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('phone', fn (?string $stored): string => null === $stored ? '' : $this->phones->grouped($stored))];
    }
}
