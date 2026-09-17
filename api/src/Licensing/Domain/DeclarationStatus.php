<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** Where a declared payment stands: the operator decides once. */
enum DeclarationStatus: string
{
    case Declared = 'declared';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
