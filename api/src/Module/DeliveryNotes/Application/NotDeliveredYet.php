<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

/** A delivered copy is of a note whose goods arrived: a draft, a note only validated and a cancelled one have none. */
final class NotDeliveredYet extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only a delivered note has a delivered copy.');
    }
}
