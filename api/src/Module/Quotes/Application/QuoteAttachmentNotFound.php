<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** A file the quote does not carry. */
final class QuoteAttachmentNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such file on this quote.');
    }
}
