<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/** Whom a kind of notification is about, which says where a person chooses how it is told. */
enum NotificationAudience: string
{
    /** The members of one company: chosen per company. */
    case Company = 'company';
    /** The account itself, such as an invitation to a company not yet joined: chosen once. */
    case Personal = 'personal';
    /** The platform's operators, who belong to no company: chosen once. */
    case Platform = 'platform';
}
