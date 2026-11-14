<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Application\CompanyProof\EntryProof;
use App\Identity\Application\Mfa\Passkey\PasskeyAssertions;
use App\Identity\Application\Mfa\Passkey\PasskeyRefused;
use App\Identity\Application\Mfa\SecondFactorRefused;
use App\Identity\Application\Mfa\SecondFactorUnreadable;
use App\Identity\Application\Mfa\VerifySecondFactor;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * A signed-in person proves who they are again, at the moment of something a stranger at the same screen must not be
 * able to do: leaving customer view is the first, since a customer looking at the screen would otherwise only have to
 * press the button. The proof is what `ScreenProofs` asks: the password, with the authenticator code beside it where a
 * company of the account requires a second factor, or one of the account's passkeys. A recovery code or an
 * authenticator code alone is not enough, as they are for replacing the recovery codes: one unlocked phone holding the
 * session and the authenticator would otherwise pass. Success and refusal are both audited.
 *
 * A proof the API checks itself (an export: docs/SPEC.md § 7, audit H-b2) holds for a few minutes of this sign-in, so
 * several files in a row ask once, and a screen left open afterwards hands nothing to the next person. It is remembered
 * with whether a second factor was in it, and where the account needs one, only such a proof counts.
 */
final readonly class ConfirmStepUp implements DeclaresAuditActions
{
    public const string CONFIRMED = 'auth.step_up';
    public const string REFUSED = 'auth.step_up_refused';
    public const string EXHAUSTED = 'auth.step_up_exhausted';

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private PasskeyAssertions $assertions,
        private AuditTrail $audit,
        private StepUpProofs $proofs,
        private ScreenProofs $asked,
        private VerifySecondFactor $secondFactor,
        private DemoAccounts $demoAccounts,
        private ClockInterface $clock,
        #[Autowire(param: 'app.step_up.valid_for')]
        private string $validFor,
        /** Wrong answers a sign-in may give before it ends. */
        #[Autowire(param: 'app.step_up.attempts')]
        private int $attempts,
    ) {
    }

    /**
     * Whether this account proved itself in this sign-in within the last few minutes. A demo visitor's account always
     * has: it has nothing to prove itself with, and the sandbox it opens holds nothing of anyone's.
     */
    public function isRecent(Uuid $userId): bool
    {
        if ($this->demoAccounts->isDemo($userId)) {
            return true;
        }
        $user = $this->users->ofId($userId);
        $at = $this->proofs->lastFor($userId, null !== $user && $this->asked->needsSecondFactor($user));

        return null !== $at && $this->clock->now() <= $at->add(new \DateInterval($this->validFor));
    }

    /**
     * A wrong answer here is this sign-in's own, never a step toward the account lock (docs/SPEC.md § 7, the C-F3 ruling):
     * a customer typing at the customer screen must not lock the clerk out everywhere. The last wrong answer the deployment
     * allows (app.step_up.attempts) ends the sign-in instead, and a new one needs the password. A locked account is
     * refused all the same, without counting.
     *
     * @throws StepUpPasskeyRequired when only the account's passkey proves it
     * @throws StepUpRefused         when the password is not the account's
     * @throws StepUpCodeRefused     when the code asked beside it is missing, wrong or spent
     * @throws StepUpExhausted       when that wrong answer was the last this sign-in may give
     */
    public function withPassword(Uuid $userId, string $password, ?string $code, ?\DateTimeImmutable $now = null): void
    {
        $user = $this->users->ofId($userId);

        $this->asAsked($userId, $password, $code, null === $user ? new EntryProof(false, false) : $this->asked->ofStepUp($user), $now);
    }

    /**
     * The password, and the code beside it where `$asked` says so, checked both before the proof is remembered: a right
     * password beside a wrong code proves nothing. A company's entry asks through here with what it asks.
     *
     * @throws StepUpPasskeyRequired
     * @throws StepUpRefused
     * @throws StepUpCodeRefused
     * @throws StepUpExhausted
     */
    public function asAsked(Uuid $userId, string $password, ?string $code, EntryProof $asked, ?\DateTimeImmutable $now = null): void
    {
        $user = $this->users->ofId($userId);

        if (null === $user || $user->isLockedAt($this->clock->now())) {
            $this->audit->record(new AuditEntry('user', $userId, self::REFUSED, $userId, ['method' => 'password']));

            throw new StepUpRefused();
        }
        if (!$asked->password) {
            throw new StepUpPasskeyRequired();
        }
        if ('' === $password || !$this->hasher->verify($user->getPasswordHash(), $password)) {
            $this->wrong($userId, 'password');
        }
        if ($asked->code) {
            try {
                $this->secondFactor->handle($userId, $code ?? '');
            } catch (SecondFactorRefused|SecondFactorUnreadable) {
                $this->countWrong($userId, 'code');

                throw new StepUpCodeRefused();
            }
        }

        $this->audit->record(new AuditEntry('user', $userId, self::CONFIRMED, $userId, ['method' => $asked->code ? 'password_code' : 'password']));
        $this->proofs->remember($userId, $now ?? $this->clock->now(), $asked->code);
    }

    /**
     * @throws StepUpRefused   when the credential is not one of the account's passkeys answering the options
     * @throws StepUpExhausted when that wrong answer was the last this sign-in may give
     */
    public function withPasskey(Uuid $userId, string $optionsJson, string $credentialJson, ?\DateTimeImmutable $now = null): void
    {
        $user = $this->users->ofId($userId);

        try {
            if (null === $user) {
                throw new StepUpRefused();
            }
            // The passkey's new signature counter is saved by the verification.
            $this->assertions->verify($user, $optionsJson, $credentialJson, $now ?? $this->clock->now());
        } catch (PasskeyRefused|StepUpRefused) {
            $this->wrong($userId, 'passkey');
        }

        $this->audit->record(new AuditEntry('user', $userId, self::CONFIRMED, $userId, ['method' => 'passkey']));
        // A passkey with user verification is something held and something known or been: a second factor in itself.
        $this->proofs->remember($userId, $now ?? $this->clock->now(), true);
    }

    /**
     * @throws StepUpRefused
     * @throws StepUpExhausted
     */
    private function wrong(Uuid $userId, string $method): never
    {
        $this->countWrong($userId, $method);

        throw new StepUpRefused();
    }

    /**
     * A wrong answer to a proof asked beside this one (the authenticator code a company asks with the password), counted
     * against the same budget of this sign-in.
     *
     * @throws StepUpExhausted when that wrong answer was the last this sign-in may give
     */
    public function countWrong(Uuid $userId, string $method): void
    {
        $this->audit->record(new AuditEntry('user', $userId, self::REFUSED, $userId, ['method' => $method]));
        if ($this->proofs->failed($userId) >= $this->attempts) {
            $this->audit->record(new AuditEntry('user', $userId, self::EXHAUSTED, $userId, ['method' => $method]));

            throw new StepUpExhausted();
        }
    }

    public function auditActions(): array
    {
        return [
            self::CONFIRMED => new Recorded('user', AuditScope::Outside),
            self::EXHAUSTED => new Recorded('user', AuditScope::Outside),
            self::REFUSED => new Recorded('user', AuditScope::Outside),
        ];
    }
}
