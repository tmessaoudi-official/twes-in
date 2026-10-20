<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Languages;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps an account's language on the one its person's screens show, which mails sent while nobody is signed in can
 * read. No language named leaves the account as it is; a language the screens do not speak is never an account's.
 */
final readonly class KeepAccountLanguage
{
    public function __construct(private UserRepository $users, private Transactions $transactions, private Languages $languages)
    {
    }

    public function chosen(Uuid $userId, ?string $tag): void
    {
        if (null === $tag || !\in_array($tag, $this->languages->interface(), true)) {
            return;
        }
        $this->transactions->run(function () use ($userId, $tag): void {
            $user = $this->users->lockedOfId($userId);
            if (null === $user) {
                return;
            }
            $user->changeLocale($tag);
            $this->users->save($user);
        });
    }
}
