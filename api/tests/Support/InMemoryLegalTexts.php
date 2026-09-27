<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;
use App\Legal\Domain\LegalTextRepository;

final class InMemoryLegalTexts implements LegalTextRepository
{
    /** @var list<LegalText> */
    public array $rows = [];

    public function latest(LegalPage $page, LegalLanguage $language): ?LegalText
    {
        $latest = null;
        foreach ($this->rows as $row) {
            if ($row->getPage() === $page && $row->getLanguage() === $language
                && (null === $latest || $row->getCreatedAt() >= $latest->getCreatedAt())) {
                $latest = $row;
            }
        }

        return $latest;
    }

    public function all(): array
    {
        $rows = array_reverse($this->rows);
        usort($rows, static fn (LegalText $a, LegalText $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $rows;
    }

    public function add(LegalText $text): void
    {
        $this->rows[] = $text;
    }

    public function save(LegalText $text): void
    {
    }
}
