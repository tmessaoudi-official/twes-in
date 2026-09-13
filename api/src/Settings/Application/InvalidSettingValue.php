<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

/** A value the setting's type or constraints refuse; the message names the key and the rule. */
final class InvalidSettingValue extends \RuntimeException
{
}
