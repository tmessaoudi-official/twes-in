<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

/**
 * Its owner lets a passkey alone sign their account in, or stops it. Switching it on is a new way into the account, so it
 * waits for a fresh proof of who is at the screen, as adding a passkey does; switching it off takes a way in away and
 * asks nothing. Either is told to the owner by mail, once, when it changes something.
 */
final readonly class SwitchPasskeySignIn implements DeclaresAuditActions
{
    public const string ON = 'auth.passkey_sign_in_on';
    public const string OFF = 'auth.passkey_sign_in_off';

    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private ReadSetting $settings,
        private ConfirmStepUp $stepUp,
        private AuditTrail $audit,
        private Transactions $transactions,
        private SecurityNotices $notices,
    ) {
    }

    /**
     * @throws StepUpRequired       when switching it on without a fresh proof
     * @throws PasskeySignInRefused when it cannot be switched on for this account
     */
    public function handle(Uuid $userId, bool $on, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();
        if ($on && !$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }
        $this->transactions->run(function () use ($userId, $on, $now): void {
            $user = $this->users->ofId($userId) ?? throw new \LogicException('The switch is the signed-in account\'s own.');
            if ($user->signsInWithPasskey() === $on) {
                return;
            }
            if ($on) {
                $this->mayBeSwitchedOn($user);
            }
            $user->allowPasskeySignIn($on, $now);
            $this->users->save($user);
            $this->audit->record(new AuditEntry('user', $user->getId(), $on ? self::ON : self::OFF, $user->getId()));
            $this->notices->tell($user->getId(), $on ? SecurityChange::PasskeySignInOn : SecurityChange::PasskeySignInOff);
        });
    }

    /** Whether the installation allows it at all, whoever asks. */
    public function allowedHere(): bool
    {
        return true === $this->settings->value(new SettingContext(), PasskeySignInSettings::ALLOWED);
    }

    private function mayBeSwitchedOn(User $user): void
    {
        if (!$this->allowedHere()) {
            throw new PasskeySignInRefused(PasskeySignInRefused::OFF);
        }
        if (!$user->hasTotp()) {
            throw new PasskeySignInRefused(PasskeySignInRefused::NEEDS_AUTHENTICATOR);
        }
        // A passkey made before the browser was asked says nothing of where it lives, and may well be on the device.
        foreach ($this->passkeys->ofUser($user) as $passkey) {
            if (false !== $passkey->isDiscoverable()) {
                return;
            }
        }

        throw new PasskeySignInRefused(PasskeySignInRefused::NEEDS_PASSKEY);
    }

    public function auditActions(): array
    {
        return [
            self::ON => new Recorded('user', AuditScope::Outside),
            self::OFF => new Recorded('user', AuditScope::Outside),
        ];
    }
}
