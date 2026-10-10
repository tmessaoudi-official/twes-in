<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Domain;

/** Waiting for its end and still undone on request, put back, or over for good with its copy gone. */
enum ErasureState: string
{
    case Pending = 'pending';
    case Undone = 'undone';
    case Final = 'final';
}
