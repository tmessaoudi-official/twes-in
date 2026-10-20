<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Records\Application\Merge\MergeConflict;
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
     * The records of the subject the company has among the ids, each named as a person reads it, for a screen to read.
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, string> label by id, the ids the company does not have left out
     */
    public function labels(Uuid $companyId, RecordSubject $subject, array $ids): array;

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
     * Deletes the records with the rows that belong to them, copying each under the removal by the statement that
     * deletes it.
     *
     * @param list<Uuid>            $ids
     * @param list<RecordReference> $owned the references whose rows go with the records
     */
    public function remove(Uuid $companyId, Uuid $removalId, RecordSubject $subject, array $ids, array $owned): void;

    /**
     * Whether the record to keep sits, through the reference that holds a subject's records under one another, under
     * one of those merged into it.
     *
     * @param list<Uuid> $mergedIds
     */
    public function descendsFrom(Uuid $companyId, Uuid $keptId, array $mergedIds, RecordReference $holding): bool;

    /**
     * Points every row the references move from the merged record at the record kept, noting each under the merge for
     * its undo; a row the record kept already has a twin of is copied under the merge and deleted instead.
     *
     * @param list<RecordReference> $moving the references a merge moves
     *
     * @throws MergeConflict when a unique index refuses a row moved
     */
    public function moveTo(Uuid $companyId, Uuid $mergeId, Uuid $keptId, Uuid $mergedId, array $moving): void;

    /**
     * Points back at the record merged away every row the merge moved that still names the record kept; one changed
     * since stays as it was set.
     *
     * @throws RemovalConflict when something made since stands in a row's way
     */
    public function moveBack(Uuid $companyId, Uuid $mergeId): void;

    /**
     * Puts every copied row back, all or nothing, and lets the copy go.
     *
     * @throws RemovalConflict when something made since stands in a row's way
     */
    public function restore(Uuid $companyId, Uuid $removalId): void;

    /**
     * Deletes the copy, and says which files its rows named that nothing names any more, for the caller to let go.
     *
     * @return list<Uuid>
     */
    public function forget(Uuid $companyId, Uuid $removalId): array;

    /**
     * The records among the ids already merged into another, which no merge or delete takes any more.
     *
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    public function mergedAwayAmong(Uuid $companyId, RecordSubject $subject, array $ids): array;

    /**
     * The first of the subject's columns that must agree in which the records hold different values, if any.
     *
     * @param list<Uuid> $ids
     */
    public function disagreeing(Uuid $companyId, RecordSubject $subject, array $ids): ?string;

    /**
     * Switches the records merged away off and points them at the record kept, noting both columns under the merge, so
     * that its undo switches them back on and lets them go.
     *
     * @param list<Uuid> $mergedIds
     */
    public function alias(Uuid $companyId, Uuid $mergeId, RecordSubject $subject, Uuid $keptId, array $mergedIds): void;

    /**
     * What names the record kept and is dated at or after the moment by its reference's trading column, among the rows
     * a merge would not move: the record kept traded since.
     *
     * @param list<RecordReference> $transacting
     *
     * @return list<array{table: string, count: int}> by table, those with no row left out
     */
    public function tradedSince(Uuid $companyId, Uuid $keptId, array $transacting, \DateTimeImmutable $since): array;

    /**
     * What a merge would move from each record to the record kept, and what would stay naming it, by table.
     *
     * @param list<Uuid>            $ids
     * @param list<RecordReference> $naming
     *
     * @return array<string, array{moves: list<array{table: string, count: int}>, stays: list<array{table: string, count: int}>}> by record id
     */
    public function merging(Uuid $companyId, array $ids, array $naming): array;
}
