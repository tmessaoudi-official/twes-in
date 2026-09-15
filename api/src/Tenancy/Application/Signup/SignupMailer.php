<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

/** The port signup sends through; one adapter, Symfony Mailer over the configured DSN. */
interface SignupMailer
{
    public function link(SignupLinkMail $mail): void;

    public function accountExists(AccountExistsMail $mail): void;
}
