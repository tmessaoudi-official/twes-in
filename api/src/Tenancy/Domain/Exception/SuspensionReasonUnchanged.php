<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain\Exception;

/**
 * A suspended company given the reason it already has, while a second suspension says its reason anew: nothing would
 * change and nobody would be told, which the operator is told rather than answered as if it had.
 */
final class SuspensionReasonUnchanged extends \DomainException
{
}
