<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

/** The establishment has no default series for the document type, so the document cannot be numbered. */
final class NoNumberingSeries extends \RuntimeException
{
}
