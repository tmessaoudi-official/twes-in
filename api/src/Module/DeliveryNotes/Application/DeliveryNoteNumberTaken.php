<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Tenancy\Application\Numbering\NumberAlreadyTaken;

/**
 * The number a series gave is already on another delivery note of the company, which happens when two establishments
 * number with the same format: numbers are unique within a company, the allocation's rule.
 */
final class DeliveryNoteNumberTaken extends NumberAlreadyTaken
{
}
