<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

/** The record to keep sits under one merged into it: what that one held would come to hold its own holder. */
final class MergeCycle extends \DomainException
{
}
