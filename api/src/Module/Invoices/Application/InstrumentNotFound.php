<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** The invoice has no such instrument, or it is another company's. */
final class InstrumentNotFound extends \RuntimeException
{
}
