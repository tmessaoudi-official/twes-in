<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

final class StockLocationCodeTaken extends \RuntimeException
{
    /**
     * `$taken` and not `$code`: `\Exception` already declares a non-readonly `$code`, and a promoted readonly
     * property of that name is a fatal redeclaration at load time rather than a compile error where it is written.
     *
     * @param string|null $taken the code that is taken, named when the caller sent more than one — a repeat asks for
     *                           a run of codes at once, so "this code" would leave the person to work out which
     */
    public function __construct(public readonly ?string $taken = null)
    {
        parent::__construct(null === $taken
            ? 'Another location of this establishment already has this code.'
            : \sprintf('Another location of this establishment already has the code %s.', $taken));
    }
}
