<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

/** A change the expense's status does not allow: revising or deleting what is recorded, paying a draft, paying twice. */
final class ExpenseTransitionRefused extends \DomainException
{
}
