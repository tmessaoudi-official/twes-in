<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application\Tej;

/**
 * A Tunisian matricule fiscal as the TEJ platform names a taxpayer: its seven digits and check letter, and whether it
 * is a legal person (PM) or a natural one (PP). The full matricule is `1234567A/B/M/000` (docs/fiscal/TN.md § 8):
 * the check letter, the VAT code, the category code and the establishment; TEJ keeps the first eight characters
 * (`\d{7}[A-Z]`) and reads the person's kind from the category code.
 */
final readonly class TejMatricule
{
    /** The preset's identifier key. */
    public const string KEY = 'matricule_fiscal';

    private const string SHAPE = '#^(\d{7}[A-Z])/?([A-Z])/?([A-Z])/?(\d{3})$#';

    /**
     * The category codes whose person is known: M a legal person, P and C natural ones (C a trader). Any other code,
     * N and E among them, is not guessed (docs/research/tax-data-tunisia.md § 1.2 lists C/M/N/P as the codes the TEIF
     * schema accepts, without saying which kind of person N is).
     */
    private const array PERSON_BY_CATEGORY = ['M' => 'PM', 'P' => 'PP', 'C' => 'PP'];

    private function __construct(
        /** Seven digits and the check letter. */
        public string $identifier,
        /** PM or PP; null when the category code names no kind the platform knows. */
        public ?string $category,
    ) {
    }

    /** Null for a value that is not a matricule. */
    public static function parse(string $value): ?self
    {
        if (1 !== preg_match(self::SHAPE, strtoupper(trim($value)), $parts)) {
            return null;
        }

        return new self($parts[1], self::PERSON_BY_CATEGORY[$parts[3]] ?? null);
    }
}
