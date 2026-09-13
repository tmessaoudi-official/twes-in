<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

/** A level the setting does not allow, or one the context has no subject for. */
final class SettingLevelRefused extends \RuntimeException
{
}
