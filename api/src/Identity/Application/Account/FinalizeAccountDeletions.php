<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\JournalAddresses;
use App\Identity\Domain\Repository\UserRepository;
use App\Settings\Application\ForgetSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;

/**
 * Deletes the accounts whose deletion was asked for longer ago than the days the platform sets, each in a transaction of
 * its own. What is only the account's goes with it in the database (sessions, factors, memberships, its notifications),
 * its preferences go too, and its address leaves the journal; every entry about it stays, under an id that no longer
 * names anyone. The records a company keeps (an invoice issued, a payment recorded) keep that id as well. An account that
 * has become a company's only owner since it asked is kept, for that company would be left with no one to run it, and
 * is tried again the next night.
 */
final readonly class FinalizeAccountDeletions
{
    public const string DELETED = 'account.deleted';

    public function __construct(
        private UserRepository $users,
        private CompaniesOwnedAlone $ownedAlone,
        private JournalAddresses $journal,
        private ForgetSettings $settings,
        private ReadSetting $readSetting,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return array{deleted: int, kept: int} how many accounts were deleted, and how many kept as a company's only owner */
    public function handle(): array
    {
        $days = $this->readSetting->value(new SettingContext(), AccountDeletionSettings::DAYS);
        if (!\is_int($days)) {
            throw new \UnexpectedValueException('The days before an account deletion is final are not a number.');
        }
        $deleted = $kept = 0;
        foreach ($this->users->deletionsRequestedBefore($this->clock->now()->modify(\sprintf('-%d days', $days))) as $due) {
            $userId = $due->getId();
            $done = $this->transactions->run(function () use ($userId): ?bool {
                $user = $this->users->lockedOfId($userId);
                // Signed in again since the list was read: taken back.
                if (null === $user || !$user->isDeletionPending()) {
                    return null;
                }
                if ([] !== $this->ownedAlone->of($userId)) {
                    return false;
                }
                $address = $user->getEmail()->value;
                $this->settings->at(SettingAddress::user($userId));
                $this->users->remove($user);
                $this->audit->record(new AuditEntry(ManageAccounts::ENTITY_TYPE, $userId, self::DELETED, null));
                $this->journal->erase($address);

                return true;
            });
            $deleted += true === $done ? 1 : 0;
            $kept += false === $done ? 1 : 0;
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }
}
