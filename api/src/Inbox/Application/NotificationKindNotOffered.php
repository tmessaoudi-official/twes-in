<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/**
 * A choice about a kind this person is not told there: another company's, one their role never receives, or a kind
 * nobody declared. All read the same, so asking learns nothing about a company one is not in.
 */
final class NotificationKindNotOffered extends \RuntimeException
{
}
