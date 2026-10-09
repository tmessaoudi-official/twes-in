<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/**
 * One kind of notification a context publishes, and who receives it: a member is offered a choice only for what their
 * role is actually told, in a company whose module for it is on.
 */
final readonly class NotificationKind
{
    /**
     * @param string      $type       the dotted code the notification carries
     * @param string|null $module     the module it belongs to; switched off, nothing of it is told
     * @param string|null $permission the permission its publisher tells, null when every member is told
     * @param string|null $role       the one role its publisher tells, such as the owner, null when any role may be
     * @param bool        $mailed     false for a kind its context already mails on its own, such as an invitation, whose
     *                                mail is the only way to accept it
     */
    public function __construct(
        public string $type,
        public NotificationAudience $audience,
        public ?string $module = null,
        public ?string $permission = null,
        public ?string $role = null,
        public bool $mailed = true,
    ) {
    }
}
