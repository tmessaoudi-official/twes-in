<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Records;

use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Application\Mfa\Passkey\PasskeyAssertions;
use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Application\PasswordHasher;
use App\Identity\Application\SecretCipher;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpProofs;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\RecoveryCodeRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordStore;
use App\Records\Application\RestoreRecords;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Records\Domain\ValueObject\RemovalState;
use App\Shared\Application\LiveChanges;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\StepUpDoubles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Restoring what a delete or an import's undo put in « Récemment supprimés » asks no proof, as it only puts back what
 * the person's own delete took; an operator may make every restore ask one (app.step_up.on_restore). A merge's undo
 * asks one whatever the operator sets.
 */
final class RestoreRecordsTest extends TestCase
{
    private MockClock $clock;
    private Company $company;
    private Uuid $person;
    private ?\DateTimeImmutable $provedAt = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-02 10:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis', new \DateTimeImmutable());
        $this->person = Uuid::v7();
    }

    public function testByDefaultADeleteAndAnUndoneImportComeBackWithoutAProof(): void
    {
        foreach ([$this->deleted(), $this->undoneImport()] as $removal) {
            self::assertSame(RemovalState::Restored, $this->restore($removal, onRestore: false)->getState());
        }
    }

    public function testWhenTheOperatorAsksEveryRestoreTakesARecentProof(): void
    {
        foreach ([$this->deleted(), $this->undoneImport()] as $removal) {
            try {
                $this->restore($removal, onRestore: true);
                self::fail('a restore came back without a proof the operator asks for');
            } catch (StepUpRequired) {
            }
            self::assertSame(RemovalState::Pending, $removal->getState(), 'nothing came back');
        }
    }

    public function testWhenTheOperatorAsksARecentProofLetsTheRestoreThrough(): void
    {
        $this->provedAt = $this->clock->now()->modify('-1 minute');

        self::assertSame(RemovalState::Restored, $this->restore($this->deleted(), onRestore: true)->getState());
    }

    public function testAProofOlderThanTheStepUpsWindowIsNoProof(): void
    {
        $this->provedAt = $this->clock->now()->modify('-6 minutes');

        $this->expectException(StepUpRequired::class);
        $this->restore($this->deleted(), onRestore: true);
    }

    public function testUndoingAMergeTakesAProofWhateverTheOperatorSets(): void
    {
        $merge = RecordRemoval::merge(Uuid::v7(), $this->company, 'customer', ['id' => Uuid::v7()->toRfc4122(), 'label' => 'Kept'], [['id' => Uuid::v7()->toRfc4122(), 'label' => 'Gone']], $this->person, $this->clock->now(), new \DateInterval('PT24H'));

        $this->expectException(StepUpRequired::class);
        $this->restore($merge, onRestore: false);
    }

    private function deleted(): RecordRemoval
    {
        return new RecordRemoval(Uuid::v7(), $this->company, 'customer', [['id' => Uuid::v7()->toRfc4122(), 'label' => 'Gone']], $this->person, $this->clock->now(), new \DateInterval('PT24H'));
    }

    private function undoneImport(): RecordRemoval
    {
        return RecordRemoval::import(Uuid::v7(), $this->company, 'customer', [['id' => Uuid::v7()->toRfc4122(), 'label' => 'Imported']], $this->person, $this->clock->now(), new \DateInterval('PT24H'));
    }

    private function restore(RecordRemoval $removal, bool $onRestore): RecordRemoval
    {
        $removals = $this->createStub(RecordRemovalRepository::class);
        $removals->method('lockedOfId')->willReturn($removal);

        return (new RestoreRecords(
            $this->createStub(RecordStore::class),
            $removals,
            new InMemoryAuditTrail(),
            new FakeTransactions(),
            $this->clock,
            $this->stepUp(),
            new RecordCatalogue([]),
            $this->createStub(LiveChanges::class),
            onRestore: $onRestore,
        ))->handle($this->company, $removal->getId(), $this->person);
    }

    private function stepUp(): ConfirmStepUp
    {
        $at = $this->provedAt;
        $proofs = new class($at) implements StepUpProofs {
            public function __construct(private readonly ?\DateTimeImmutable $at)
            {
            }

            public function remember(Uuid $userId, \DateTimeImmutable $at, bool $withSecondFactor): void
            {
            }

            public function lastFor(Uuid $userId, bool $withSecondFactor): ?\DateTimeImmutable
            {
                return $this->at;
            }

            public function forget(): void
            {
            }

            public function failed(Uuid $userId): int
            {
                return 1;
            }
        };
        $demo = $this->createStub(DemoAccounts::class);
        $demo->method('isDemo')->willReturn(false);

        return new ConfirmStepUp(
            $this->createStub(UserRepository::class),
            $this->createStub(PasswordHasher::class),
            new PasskeyAssertions($this->createStub(PasskeyRepository::class), $this->createStub(PasskeyCeremonies::class)),
            new InMemoryAuditTrail(),
            $proofs,
            StepUpDoubles::screenProofs(new InMemoryMemberships(), $passkeys = $this->createStub(PasskeyRepository::class)),
            StepUpDoubles::secondFactor($this->createStub(UserRepository::class), $this->createStub(RecoveryCodeRepository::class), $this->createStub(TotpCodes::class), $this->createStub(SecretCipher::class), $passkeys, $this->clock),
            $demo,
            $this->clock,
            'PT5M',
            5,
        );
    }
}
