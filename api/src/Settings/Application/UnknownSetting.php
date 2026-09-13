<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

/** A key no module declares: nothing may read or store it. */
final class UnknownSetting extends \RuntimeException
{
}
