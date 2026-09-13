<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/** No such notification for this recipient. Deliberately says nothing about whether it exists for someone else. */
final class InboxItemNotFound extends \RuntimeException
{
}
