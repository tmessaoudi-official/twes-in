<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Domain\Repository;

use App\ImportExport\Domain\Model\ImportRun;
use Symfony\Component\Uid\Uuid;

interface ImportRunRepository
{
    /** The latest committed import of this very file into this subject of this company, if there was one. */
    public function lastOf(Uuid $companyId, string $subject, string $contentHash): ?ImportRun;

    /**
     * The pairing of the latest committed import into this subject of this company that needed one, or none.
     *
     * @return array<string, string>
     */
    public function lastPairingOf(Uuid $companyId, string $subject): array;

    public function save(ImportRun $run): void;
}
