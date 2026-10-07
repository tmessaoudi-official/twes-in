<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

/** A move a quote's status does not allow: only a sent quote is accepted or refused, only an accepted one invoiced. */
final class QuoteTransitionRefused extends \DomainException
{
}
