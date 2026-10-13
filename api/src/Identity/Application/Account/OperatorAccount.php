<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

/**
 * A platform operator's account is not deleted by its owner: the last one would leave the platform with no one to run
 * it, and what an operator wrote (legal texts) names them. Another operator deactivates it instead.
 */
final class OperatorAccount extends \DomainException
{
}
