<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application;

use App\Fiscal\Domain\Exception\InvalidFiscalValue;
use App\Shared\Application\Languages;

/** A unit or a tax is named only in languages the catalogue prints documents in: a name in any other would never print. */
final readonly class DocumentLanguageNames
{
    public function __construct(private Languages $languages)
    {
    }

    /**
     * @param array<array-key, mixed>|null $names
     *
     * @return array<array-key, mixed>|null the same names
     *
     * @throws InvalidFiscalValue
     */
    public function checked(?array $names): ?array
    {
        $unknown = array_diff(array_map(strval(...), array_keys($names ?? [])), $this->languages->documents());
        if ([] !== $unknown) {
            throw new InvalidFiscalValue('names', \sprintf('Documents print in %s, not in %s.', implode(', ', $this->languages->documents()), implode(', ', $unknown)));
        }

        return $names;
    }
}
