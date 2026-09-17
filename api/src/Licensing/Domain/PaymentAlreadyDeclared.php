<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** One payment waits for a decision at a time: declaring again while one is open is refused. */
final class PaymentAlreadyDeclared extends \DomainException
{
}
