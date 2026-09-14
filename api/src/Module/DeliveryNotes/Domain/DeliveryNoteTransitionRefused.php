<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

/** A move a delivery note's status does not allow: only a validated note is delivered, a delivered one never cancelled. */
final class DeliveryNoteTransitionRefused extends \DomainException
{
}
