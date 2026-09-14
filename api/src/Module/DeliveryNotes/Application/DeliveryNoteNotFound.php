<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

/** No delivery note of this company has the id: another company's note is not found either. */
final class DeliveryNoteNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such delivery note.');
    }
}
