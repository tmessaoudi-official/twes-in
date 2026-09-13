<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Application;

final class CustomFieldKeyTaken extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This company already has a field with this key for this kind of record.');
    }
}
