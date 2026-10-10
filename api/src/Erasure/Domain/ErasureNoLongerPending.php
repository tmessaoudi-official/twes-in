<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Domain;

/** The erasure was undone already, or its time to be undone has ended: nothing of it comes back now. */
final class ErasureNoLongerPending extends \DomainException
{
}
