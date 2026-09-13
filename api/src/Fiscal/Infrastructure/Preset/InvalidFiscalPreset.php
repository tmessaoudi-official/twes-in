<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Preset;

/** A preset file that breaks a rule. Its message starts with the file and names the rule. */
final class InvalidFiscalPreset extends \RuntimeException
{
}
