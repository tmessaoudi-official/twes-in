<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

/** A change a delivery note's status no longer allows: only a draft is revised. */
final class DeliveryNoteNotDraft extends \DomainException
{
}
