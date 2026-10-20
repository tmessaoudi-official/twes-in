<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\ValueObject;

/** What a person did that « Récemment supprimés » keeps a day: records deleted, or records merged into another. */
enum RemovalKind: string
{
    case Delete = 'delete';
    case Merge = 'merge';
}
