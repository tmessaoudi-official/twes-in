<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use Symfony\Component\Uid\Uuid;

/** The records engine's rows, read and moved by table name, every statement naming the company. */
interface RecordStore
{
    /**
     * The records of the subject the company has among the ids, each named as a person reads it, locked until the
     * transaction ends so that nothing comes to name one between the count and the delete.
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, string> label by id, the ids the company does not have left out
     */
    public function lockedLabels(Uuid $companyId, RecordSubject $subject, array $ids): array;

    /**
     * What names each record, by the references that make it used.
     *
     * @param list<Uuid>            $ids
     * @param list<RecordReference> $references
     *
     * @return array<string, non-empty-list<array{table: string, column: string, count: int}>> by record id, those named
     *                                                                                         by nothing left out
     */
    public function uses(Uuid $companyId, array $ids, array $references): array;

    /**
     * Deletes the records, copying each under the removal by the statement that deletes it.
     *
     * @param list<Uuid> $ids
     */
    public function remove(Uuid $companyId, Uuid $removalId, RecordSubject $subject, array $ids): void;

    /**
     * Puts every copied row back, all or nothing, and lets the copy go.
     *
     * @throws RemovalConflict when something made since stands in a row's way
     */
    public function restore(Uuid $companyId, Uuid $removalId): void;

    /** Deletes the copy. */
    public function forget(Uuid $companyId, Uuid $removalId): void;
}
