<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\ValueObject;

use App\Fiscal\Domain\Exception\InvalidFiscalValue;
use App\Shared\Domain\Service\TypedText;

/**
 * What a unit or a tax is called in each language a document prints in, beside its name: a document in a language it
 * names prints that, any other prints the name. Which languages documents print in is the catalogue's, checked where
 * it is read; here a name is text and a language is a tag.
 */
final class LanguageNames
{
    /**
     * The names trimmed, a blank one left out so the name prints in its place, in the order of their languages: the
     * database keeps them in an order of its own, so two sets of the same names compare equal only once ordered.
     *
     * @param array<array-key, mixed> $names
     *
     * @return array<string, string>
     *
     * @throws InvalidFiscalValue
     */
    public static function of(array $names, int $max): array
    {
        $kept = [];
        foreach ($names as $language => $name) {
            if (!\is_string($language) || '' === TypedText::trimmed($language) || !\is_string($name)) {
                throw new InvalidFiscalValue('names', 'Names are given by language, each as text.');
            }
            $name = TypedText::trimmed($name);
            if (mb_strlen($name) > $max) {
                throw new InvalidFiscalValue('names', \sprintf('A name has at most %d characters in any language.', $max));
            }
            if ('' !== $name) {
                $kept[$language] = $name;
            }
        }

        ksort($kept, \SORT_STRING);

        return $kept;
    }
}
