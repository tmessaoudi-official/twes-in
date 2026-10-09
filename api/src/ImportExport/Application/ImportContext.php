<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use Symfony\Component\Uid\Uuid;

/**
 * One run of a file, as every row of it sees it: the id the run is kept under once committed, which what a row writes
 * elsewhere may carry to read as the import's, and the switches the person ticked among those the subject offers.
 */
final readonly class ImportContext
{
    /** @param list<string> $ticked the keys of the subject's switches the person ticked */
    public function __construct(
        public Uuid $runId,
        private array $ticked = [],
    ) {
    }

    public function ticked(string $switch): bool
    {
        return \in_array($switch, $this->ticked, true);
    }
}
