<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

/** A record already merged into another is no longer one to merge, merge into, or delete: what it was is its alias. */
final class MergeAlias extends \RuntimeException
{
}
