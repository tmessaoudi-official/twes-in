<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

/** No erasure of this company has this id. */
final class ErasureNotFound extends \DomainException
{
}
