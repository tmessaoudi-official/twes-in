<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

/**
 * Where a quote stands. Past its validity date a sent quote reads as expired, which is worked out from the company's
 * day and never stored: the price no longer binds, and the company may still accept it.
 */
enum QuoteStatus: string
{
    /** Still being written: no number, every field revisable. */
    case Draft = 'draft';
    /** Numbered and fixed, its price binding until its validity date: handed to the customer. */
    case Sent = 'sent';
    /** The customer agreed. */
    case Accepted = 'accepted';
    /** The customer declined. */
    case Refused = 'refused';
    /** A draft that will not be sent. */
    case Cancelled = 'cancelled';
}
