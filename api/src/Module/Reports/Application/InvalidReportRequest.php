<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/** What was asked of a report cannot be read: a period backwards or too long, an establishment a report has no say over. */
final class InvalidReportRequest extends \DomainException
{
}
