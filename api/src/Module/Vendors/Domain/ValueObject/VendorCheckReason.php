<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain\ValueObject;

/**
 * Why a vendor is « À vérifier » without anyone marking it, each reason clearing itself once fixed: a name a file could
 * not vouch for (a sheet titled with two vendors, one vendor spelt two ways), which a rename or the person clears.
 */
enum VendorCheckReason: string
{
    case NameDoubtful = 'name_doubtful';
}
