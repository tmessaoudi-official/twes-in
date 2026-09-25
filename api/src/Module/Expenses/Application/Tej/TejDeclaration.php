<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application\Tej;

/**
 * A company's monthly declaration of the withholdings it operated, for Tunisia's TEJ platform (cahier des charges
 * TEJ, September 2026): the declarant's matricule, the month the payments were made in, and one certificate per
 * payment. Always an initial filing: a rectifying one modifies or cancels certificates already on the platform, which
 * twes-in does not know.
 */
final readonly class TejDeclaration
{
    /** The filing is the month's first ("0 : Initiale"). */
    public const string INITIAL = '0';

    /** @param list<TejCertificate> $certificates */
    public function __construct(
        /** Seven digits and the check letter. */
        public string $declarant,
        /** PM or PP. */
        public string $declarantCategory,
        public int $year,
        public int $month,
        public array $certificates,
    ) {
    }

    /** `[MATRICULE]-[EXERCICE]-[mois]-[code acte].xml`, as the cahier's § 5 names the file: `0001238L-2024-01-0.xml`. */
    public function fileName(): string
    {
        return \sprintf('%s-%04d-%02d-%s.xml', $this->declarant, $this->year, $this->month, self::INITIAL);
    }
}
