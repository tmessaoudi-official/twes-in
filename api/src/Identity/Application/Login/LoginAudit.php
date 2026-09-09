<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

/** Every authentication event is an audit_log row (entity_type "user", action "auth.*"), by ruling: one table, one reader. */
final class LoginAudit
{
    public const string ENTITY_TYPE = 'user';
    public const string LOGIN = 'auth.login';
    public const string LOGIN_FAILED = 'auth.login_failed';
    public const string LOGOUT = 'auth.logout';

    private function __construct()
    {
    }
}
