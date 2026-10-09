<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

/** A file the loss does not carry. */
final class StockLossAttachmentNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such file on this loss.');
    }
}
