<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Application;

use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Shared\Domain\ValueObject\CheckMark;

/** A vendor as it is written: its number, its profile and whether it is still bought from. */
final readonly class VendorInput
{
    /**
     * @param CheckMark|null $mark         « À vérifier » and its note as written; null keeps them
     * @param bool|null      $nameDoubtful whether the name is still in doubt, as a form says it: it can only clear one;
     *                                     null keeps it
     * @param bool           $doubtsName   a file's row unsure of the name, which puts the doubt there
     */
    public function __construct(
        public string $number,
        public VendorProfile $profile,
        public bool $isActive,
        public ?CheckMark $mark = null,
        public ?bool $nameDoubtful = null,
        public bool $doubtsName = false,
    ) {
    }

    /** The same input with this profile: its registration numbers as the preset stores them. */
    public function withProfile(VendorProfile $profile): self
    {
        return clone ($this, ['profile' => $profile]);
    }

    /** The same input with this « À vérifier »: the one a phone's doubt adds to what was written. */
    public function withMark(CheckMark $mark): self
    {
        return clone ($this, ['mark' => $mark]);
    }
}
