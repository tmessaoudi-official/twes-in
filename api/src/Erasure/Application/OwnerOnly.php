<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

/** Erasing a company's data, and putting it back, is its owner's alone, whatever another role is granted. */
final class OwnerOnly extends \DomainException
{
}
