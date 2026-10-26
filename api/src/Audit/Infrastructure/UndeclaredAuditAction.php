<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure;

/** An audit action recorded without its declaration, or where its declaration says it is not: refused while debugging. */
final class UndeclaredAuditAction extends \LogicException
{
}
