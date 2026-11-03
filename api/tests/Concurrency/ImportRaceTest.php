<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\SaleRaceTestCase;
use App\Tests\Concurrency\Support\Steps\ImportFileAndLinger;

/**
 * The same file imported twice at the same moment. A file committed before is a warning, « déjà importé le … », never a
 * refusal (docs/SPEC.md § 7, 2026-10-09 11:12): two runs may both commit, but the later one is told, as it is when the
 * two come one after the other. A file whose rows only add, as received stock does, is where an untold second run
 * doubles what it adds unseen; a file whose rows a constraint makes unique keeps one record whichever run wins.
 */
final class ImportRaceTest extends SaleRaceTestCase
{
    protected function saleWithoutStock(): string
    {
        return 'allow';
    }

    protected function onHand(): string
    {
        return '1';
    }

    public function testTheSameStockFileImportedTwiceAtOnceTellsTheLaterRunItWasAlreadyImported(): void
    {
        $outcomes = Race::run(ImportFileAndLinger::class, 2, ['company' => $this->company->getId()->toRfc4122(), 'subject' => 'opening-stock', 'mode' => 'upsert', 'actor' => $this->sellerId, 'csv' => "reference,stock_add\nART-001,5\n", 'lingerMs' => 400]);

        $described = implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe().' '.json_encode($outcome->value), $outcomes));
        self::assertSame([Outcome::OK, Outcome::OK], array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes), $described);
        $told = 0;
        foreach ($outcomes as $outcome) {
            self::assertIsArray($outcome->value, $described);
            self::assertTrue($outcome->value['committed'] ?? null, 'a warning never stops a run: '.$described);
            $told += null === ($outcome->value['alreadyImportedAt'] ?? null) ? 0 : 1;
        }
        self::assertSame(1, $told, "the run committing second is told the file was already imported, as it is one after the other\n$described");
        self::assertSame(2, $this->runsOf('opening-stock'), 'each run kept, so each can be undone');
        self::assertSame(['11.000'], array_column($this->levels(), 'quantity'), 'one and twice five: both runs added, the second one told');
    }

    public function testTheSameCustomerFileImportedTwiceAtOnceCreatesEachCustomerOnce(): void
    {
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();

        $outcomes = Race::run(ImportFileAndLinger::class, 2, ['company' => $this->company->getId()->toRfc4122(), 'subject' => 'customers', 'mode' => 'create', 'actor' => $this->sellerId, 'csv' => "number,name\nCLI-0300,Atlas Bois\n", 'lingerMs' => 400]);

        $described = implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes));
        $committed = array_filter($outcomes, static fn (Outcome $outcome): bool => Outcome::OK === $outcome->kind && \is_array($outcome->value) && true === ($outcome->value['committed'] ?? null));
        self::assertCount(1, $committed, "one run stores the file, the other stores nothing\n$described");
        self::assertSame(1, Rows::int($this->em()->getConnection()->fetchOne("SELECT count(*) FROM customer WHERE company_id = ? AND number = 'CLI-0300'", [$this->company->getId()->toRfc4122()])), 'the customer once');
        self::assertSame(1, $this->runsOf('customers'), 'one run kept, the one that stored the customer');
    }

    private function runsOf(string $subject): int
    {
        return Rows::int($this->em()->getConnection()->fetchOne('SELECT count(*) FROM import_run WHERE company_id = ? AND subject = ?', [$this->company->getId()->toRfc4122(), $subject]));
    }
}
