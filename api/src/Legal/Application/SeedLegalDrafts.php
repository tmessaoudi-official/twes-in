<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Legal\Domain\LegalText;
use App\Legal\Domain\LegalTextRepository;
use Psr\Clock\ClockInterface;

/**
 * Writes each shipped draft as a first version where its page has none in its language, so a new platform shows a
 * page rather than nothing, marked unvalidated. Never over a version that exists: what an operator wrote is theirs.
 * Run at every start (infra/api/docker-entrypoint.sh), so a draft added in a release reaches existing platforms.
 */
final readonly class SeedLegalDrafts
{
    public function __construct(private LegalDrafts $drafts, private LegalTextRepository $texts, private ClockInterface $clock)
    {
    }

    /** @return int how many drafts were written */
    public function seed(): int
    {
        $written = 0;
        foreach ($this->drafts->all() as $draft) {
            if (null !== $this->texts->latest($draft->page, $draft->language)) {
                continue;
            }
            $this->texts->add(LegalText::draft($draft->page, $draft->language, $draft->body, null, $this->clock->now()));
            ++$written;
        }

        return $written;
    }
}
