<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/** A closing the books refuse: a day not before today, or one before the day already closed. Its message names the field. */
final class InvalidClosing extends \DomainException
{
}
