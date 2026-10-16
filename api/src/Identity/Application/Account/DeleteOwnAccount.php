<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Domain\Repository\UserRepository;
use App\Settings\Application\ReaderFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A person asks for their own account to be deleted, having just proved who they are. The account closes at once (every
 * session ends), a mail says when the deletion is final, and signing in again before then takes it back
 * (RecordSuccessfulLogin); FinalizeAccountDeletions deletes it after the days the platform sets. The only owner of a
 * company is refused with those companies named, and so is a platform operator.
 */
final readonly class DeleteOwnAccount
{
    public const string REQUESTED = 'account.deletion_requested';
    public const string CANCELLED = 'account.deletion_cancelled';

    public function __construct(
        private UserRepository $users,
        private CompaniesOwnedAlone $ownedAlone,
        private ConfirmStepUp $stepUp,
        private ReadSetting $settings,
        private AccountDeletionMailer $mailer,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private ReaderFormats $formats,
    ) {
    }

    /**
     * When the deletion becomes final.
     *
     * @throws StepUpRequired|SoleOwner|OperatorAccount
     */
    public function request(Uuid $userId): \DateTimeImmutable
    {
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }
        $days = $this->days();
        [$user, $finalAt] = $this->transactions->run(function () use ($userId, $days): array {
            $user = $this->users->lockedOfId($userId) ?? throw new AccountNotFound(\sprintf('No account %s.', $userId->toRfc4122()));
            if ($user->isPlatformOperator()) {
                throw new OperatorAccount('A platform operator\'s account is deactivated by another operator.');
            }
            $alone = $this->ownedAlone->of($userId);
            if ([] !== $alone) {
                throw new SoleOwner($alone);
            }
            $now = $this->clock->now();
            $user->requestDeletion($now);
            $this->users->save($user);
            $this->audit->record(new AuditEntry(ManageAccounts::ENTITY_TYPE, $userId, self::REQUESTED, $userId, ['email' => $user->getEmail()->value]));

            return [$user, $now->modify(\sprintf('+%d days', $days))];
        });
        $this->mailer->send(new AccountDeletionMail($user->getEmail()->value, $user->getLocale(), $days, $finalAt, $this->formats->of($userId, null)['dateFormat']));

        return $finalAt;
    }

    private function days(): int
    {
        $days = $this->settings->value(new SettingContext(), AccountDeletionSettings::DAYS);
        if (!\is_int($days)) {
            throw new \UnexpectedValueException('The days before an account deletion is final are not a number.');
        }

        return $days;
    }
}
