<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** The number a quote series gave is already on another quote of the company: the series of two establishments overlap. */
final class QuoteNumberTaken extends \RuntimeException
{
}
