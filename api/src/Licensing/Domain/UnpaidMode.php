<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** What a company gets once its grace period ends unpaid: the operator chooses, per company or for the platform. */
enum UnpaidMode: string
{
    /** View, print and export; nothing is created or changed. Issued documents stay reachable, as the law requires. */
    case ReadOnly = 'read_only';
    /** Nothing but the way out: seeing the subscription and declaring a payment. */
    case Locked = 'locked';
}
