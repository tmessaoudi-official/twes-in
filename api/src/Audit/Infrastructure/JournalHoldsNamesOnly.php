<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure;

/** An audit entry holding a way to reach or name a person or a business, which the journal names by id: refused while debugging. */
final class JournalHoldsNamesOnly extends \LogicException
{
}
