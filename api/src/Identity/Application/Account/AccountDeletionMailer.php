<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

interface AccountDeletionMailer
{
    public function send(AccountDeletionMail $mail): void;

    /** The deletion was withdrawn by the nightly run: the person is now a company's only owner. */
    public function kept(AccountDeletionKeptMail $mail): void;
}
