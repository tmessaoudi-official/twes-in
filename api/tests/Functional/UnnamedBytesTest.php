<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\RunDataChecks;
use App\Files\Application\Files;
use App\Files\Application\FileStorage;
use App\Files\Infrastructure\Scheduler\RemoveUnnamedBytesEveryHour;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Bytes under a company's folder that no file row names are a write cut short between the bytes and the row (a process
 * killed, a disk that filled): nobody will ever open them, and nothing else removes them. The hourly task removes those
 * written longer ago than app.files.unnamed_removed_after, far past what any write takes between its bytes and its row,
 * and never bytes a row names, whatever their age. The data check then reports only what the task should already have
 * taken.
 */
final class UnnamedBytesTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
    }

    public function testBytesNoRowNamesGoOnceTheirAgeIsPastAndBytesARowNamesStay(): void
    {
        $named = static::getContainer()->get(Files::class)->store($this->company, 'plan.pdf', 'application/pdf', 'named', null);
        $this->em()->flush();
        $unnamed = $this->writtenWithNoRow('unnamed');
        $written = $this->writtenAt($unnamed);
        try {
            Clock::set(new MockClock($written->modify('+23 hours')));
            $this->removeDue();
            self::assertSame('unnamed', $this->storage()->read($unnamed), 'a write that may still be on its way to its row');

            Clock::set(new MockClock($written->modify('+25 hours')));
            $this->removeDue();

            self::assertArrayNotHasKey($unnamed, $this->keys(), 'nobody could ever open it');
            self::assertSame('named', $this->storage()->read($named->getStorageKey()), 'a row names it, whatever its age');
        } finally {
            $this->forget($unnamed);
        }
    }

    /** Another company's bytes are its own: each folder is read against its own company's rows. */
    public function testEachCompanysBytesAreReadAgainstItsOwnRows(): void
    {
        $other = $this->createCompany('Droguerie');
        $theirs = static::getContainer()->get(Files::class)->store($other, 'logo.png', 'image/png', 'theirs', null);
        $this->em()->flush();
        // The same file id under this company's folder: named by a row, but another company's.
        $misplaced = 'companies/'.$this->company->getId()->toRfc4122().'/'.$theirs->getId()->toRfc4122();
        $this->storage()->write($misplaced, 'theirs');
        try {
            Clock::set(new MockClock($this->writtenAt($misplaced)->modify('+25 hours')));
            $this->removeDue();

            self::assertArrayNotHasKey($misplaced, $this->keys());
            self::assertSame('theirs', $this->storage()->read($theirs->getStorageKey()));
        } finally {
            $this->forget($misplaced);
        }
    }

    /** The check reports bytes no row names only once the task should have taken them: a task that stopped, not a write on its way. */
    public function testTheCheckReportsOnlyWhatTheTaskShouldHaveTaken(): void
    {
        $unnamed = $this->writtenWithNoRow('unnamed');
        $written = $this->writtenAt($unnamed);
        try {
            Clock::set(new MockClock($written->modify('+24 hours 30 minutes')));
            self::assertSame([], $this->storedFindings(), 'past their age, but the task may not have run since');

            Clock::set(new MockClock($written->modify('+26 hours')));
            $found = $this->storedFindings();
            self::assertSame(['1 stored files no row names'], array_map(static fn (DataFinding $finding): string => $finding->detail, $found), 'the task should have taken it an hour ago');

            $this->removeDue();
            self::assertSame([], $this->storedFindings());
        } finally {
            $this->forget($unnamed);
        }
    }

    private function writtenWithNoRow(string $contents): string
    {
        $key = 'companies/'.$this->company->getId()->toRfc4122().'/'.Uuid::v7()->toRfc4122();
        $this->storage()->write($key, $contents);

        return $key;
    }

    /** The storage dates bytes by the machine's clock, not the one the case holds: the API's clock is moved from it. */
    private function writtenAt(string $key): \DateTimeImmutable
    {
        return $this->keys()[$key] ?? self::fail('The bytes are listed.');
    }

    /** @return array<string, \DateTimeImmutable> */
    private function keys(): array
    {
        return iterator_to_array($this->storage()->keysUnder('companies/'.$this->company->getId()->toRfc4122()));
    }

    private function removeDue(): void
    {
        static::getContainer()->get(RemoveUnnamedBytesEveryHour::class)();
    }

    /**
     * @return list<DataFinding> the stored-file findings in the company, read again on every call
     *
     * @phpstan-impure
     */
    private function storedFindings(): array
    {
        return array_values(array_filter(
            static::getContainer()->get(RunDataChecks::class)->findings(),
            fn (DataFinding $finding): bool => 'files.stored' === $finding->check && $finding->companyId?->equals($this->company->getId()),
        ));
    }

    private function storage(): FileStorage
    {
        return static::getContainer()->get(FileStorage::class);
    }

    /** Deleting what is already gone succeeds, so the case leaves nothing behind whichever way it ended. */
    private function forget(string $key): void
    {
        $this->storage()->delete($key);
    }
}
