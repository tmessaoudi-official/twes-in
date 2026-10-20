<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\ValueObject;

/**
 * What a person did that « Récemment supprimés » keeps a day: records deleted, records merged into another, or the records
 * an import created taken back by « Annuler cet import ».
 */
enum RemovalKind: string
{
    case Delete = 'delete';
    case Merge = 'merge';
    case Import = 'import';
}
