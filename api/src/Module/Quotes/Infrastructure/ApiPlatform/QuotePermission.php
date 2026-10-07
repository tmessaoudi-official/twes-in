<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

/**
 * The permissions behind every quotes endpoint: reading quotes, and writing them, which covers drafting, sending,
 * recording the customer's answer and the files attached. Invoicing one also asks invoice.write, since it drafts an
 * invoice.
 */
final class QuotePermission
{
    public const string READ = 'quote.read';
    public const string WRITE = 'quote.write';
}
