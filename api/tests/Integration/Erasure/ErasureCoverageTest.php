<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasedRows;
use App\Erasure\Application\ErasureCatalogue;
use App\Erasure\Application\ErasureReference;
use App\Erasure\Infrastructure\ErasureCoverage;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An erasure copies every row it takes, so that its undo can put them all back: a table that comes to point at what a
 * part erases, by a foreign key or by a column named after it, must be one the part copies, or a reference it was told
 * how to treat. Otherwise a cascade would take rows no copy holds, or a kept row would name one that is gone.
 */
final class ErasureCoverageTest extends KernelTestCase
{
    public function testEveryTableAndColumnReachingWhatAPartErasesIsAccountedFor(): void
    {
        self::assertSame([], ErasureCoverage::unaccounted($this->connection(), $this->catalogue()->declarations()));
    }

    public function testATableACascadeWouldTakeUncopiedIsNamed(): void
    {
        $without = $this->without(static fn (ErasedRows $step): bool => 'invoice_line_tax' === $step->table);

        self::assertContains('invoice_line_tax.line_id points at invoice_line, which the part drafts erases, and is neither copied nor classified', ErasureCoverage::unaccounted($this->connection(), $without));
    }

    public function testAColumnNamedAfterAnErasedTableIsNamedThoughNoForeignKeyBacksIt(): void
    {
        $without = $this->without(null, static fn (ErasureReference $reference): bool => 'recurring_invoice' === $reference->table && 'model_invoice_id' === $reference->column);

        self::assertContains('recurring_invoice.model_invoice_id points at invoice, which the part drafts erases, and is neither copied nor classified', ErasureCoverage::unaccounted($this->connection(), $without));
    }

    public function testATableAPartNamesThatNoLongerExistsIsNamed(): void
    {
        $gone = new class implements DeclaresErasure {
            public function steps(): array
            {
                return [ErasedRows::of('drafts', 'invoice_draft_of_old', "status = 'draft'")];
            }

            public function references(): array
            {
                return [];
            }

            public function files(): array
            {
                return [];
            }
        };

        self::assertContains('invoice_draft_of_old no longer exists, and the part drafts still erases it', ErasureCoverage::unaccounted($this->connection(), [...$this->catalogue()->declarations(), $gone]));
    }

    /**
     * The declarations with some steps or references left out, as a module forgetting them would leave them.
     *
     * @param ?callable(ErasedRows): bool       $dropStep
     * @param ?callable(ErasureReference): bool $dropReference
     *
     * @return list<DeclaresErasure>
     */
    private function without(?callable $dropStep, ?callable $dropReference = null): array
    {
        $steps = [];
        $references = [];
        foreach ($this->catalogue()->declarations() as $declaration) {
            foreach ($declaration->steps() as $step) {
                if (null === $dropStep || !$dropStep($step)) {
                    $steps[] = $step;
                }
            }
            foreach ($declaration->references() as $reference) {
                if (null === $dropReference || !$dropReference($reference)) {
                    $references[] = $reference;
                }
            }
        }

        return [new readonly class($steps, $references) implements DeclaresErasure {
            /**
             * @param list<ErasedRows>       $steps
             * @param list<ErasureReference> $references
             */
            public function __construct(private array $steps, private array $references)
            {
            }

            public function steps(): array
            {
                return $this->steps;
            }

            public function references(): array
            {
                return $this->references;
            }

            public function files(): array
            {
                return [];
            }
        }];
    }

    private function catalogue(): ErasureCatalogue
    {
        return static::getContainer()->get(ErasureCatalogue::class);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
