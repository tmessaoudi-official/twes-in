<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Domain\Repository\QuoteRepository;
use App\Module\Quotes\Domain\ValueObject\QuoteSearch;
use App\Module\Quotes\Domain\ValueObject\QuoteStatus;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Quotes kept in memory, newest first. Searching, narrowing and ordering are the database's own job, tested against the
 * real repository: a page here is what it holds, paged.
 */
final class InMemoryQuotes implements QuoteRepository
{
    /** @var list<Quote> */
    public array $quotes = [];

    public function search(Uuid $companyId, QuoteSearch $search, PageRequest $page): Page
    {
        $mine = $this->ofCompany($companyId);

        return new Page(\array_slice($mine, $page->offset(), $page->size), \count($mine), $page);
    }

    public function statusCounts(Uuid $companyId, QuoteSearch $search): array
    {
        $counts = array_fill_keys(array_map(static fn (QuoteStatus $status): string => $status->value, QuoteStatus::cases()), 0);
        $mine = $this->ofCompany($companyId);
        foreach ($mine as $quote) {
            ++$counts[$quote->getStatus()->value];
        }

        return ['all' => \count($mine), 'statuses' => $counts];
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Quote
    {
        foreach ($this->ofCompany($companyId) as $quote) {
            if ($quote->getId()->equals($id)) {
                return $quote;
            }
        }

        return null;
    }

    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?Quote
    {
        return $this->ofIdInCompany($id, $companyId);
    }

    public function ofLineIdsInCompany(array $lineIds, Uuid $companyId, bool $locked): array
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $lineIds);
        $found = [];
        foreach ($this->ofCompany($companyId) as $quote) {
            foreach ($quote->getLines() as $line) {
                if (\in_array($line->getId()->toRfc4122(), $wanted, true)) {
                    $found[$quote->getId()->toRfc4122()] = $quote;
                }
            }
        }
        ksort($found);

        return array_values($found);
    }

    public function numberTaken(Uuid $companyId, string $number): bool
    {
        foreach ($this->ofCompany($companyId) as $quote) {
            if ($quote->getNumber() === $number) {
                return true;
            }
        }

        return false;
    }

    public function save(Quote $quote): void
    {
        foreach ($this->quotes as $kept) {
            if ($kept === $quote) {
                return;
            }
        }
        $this->quotes[] = $quote;
    }

    /** @return list<Quote> */
    private function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->quotes, static fn (Quote $quote): bool => $quote->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (Quote $a, Quote $b): int => [$b->getCreatedAt(), $b->getId()->toRfc4122()] <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return $mine;
    }
}
