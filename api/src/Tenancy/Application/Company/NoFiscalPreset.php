<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/** A company in a country twes-in has no fiscal preset for: it could not invoice without its taxes (docs/SPEC.md § 7). */
final class NoFiscalPreset extends \RuntimeException
{
}
