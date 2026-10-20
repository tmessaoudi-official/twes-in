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
    /**
     * The latest committed import of this very file into this subject of this company, if there was one: one taken back
     * since is no longer one, and the same file may be imported again unwarned.
     */
    public function lastOf(Uuid $companyId, string $subject, string $contentHash): ?ImportRun;

    /**
     * The pairing of the latest committed import into this subject of this company that needed one, or none.
     *
     * @return array<string, string>
     */
    public function lastPairingOf(Uuid $companyId, string $subject): array;

    public function save(ImportRun $run): void;

    public function ofId(Uuid $companyId, Uuid $id): ?ImportRun;

    /** The company's import of this id, locked until the transaction ends, so two undos of it run one after the other. */
    public function lockedOfId(Uuid $companyId, Uuid $id): ?ImportRun;

    /** The import whose undo made this removal, if one did. */
    public function ofUndoRemoval(Uuid $companyId, Uuid $removalId): ?ImportRun;

    /**
     * The company's imports into these subjects since the moment, the latest first.
     *
     * @param list<string> $subjects
     *
     * @return list<ImportRun>
     */
    public function since(Uuid $companyId, array $subjects, \DateTimeImmutable $since): array;

    /**
     * Keeps what an import created, by table.
     *
     * @param array<string, list<Uuid>> $created record ids by table
     */
    public function saveCreated(Uuid $companyId, Uuid $runId, array $created): void;

    /**
     * What an import created, by table.
     *
     * @return array<string, list<Uuid>>
     */
    public function createdBy(Uuid $companyId, Uuid $runId): array;
}
