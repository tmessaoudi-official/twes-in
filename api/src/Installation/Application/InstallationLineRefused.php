<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Installation\Application;

/** A line the installation script could not have meant: an unknown kind, a parameter it does not take, or a value out of shape. */
final class InstallationLineRefused extends \DomainException
{
}
