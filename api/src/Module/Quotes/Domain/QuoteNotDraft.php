<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

/** A change a quote's status no longer allows: only a draft is revised, sent or cancelled. */
final class QuoteNotDraft extends \DomainException
{
}
