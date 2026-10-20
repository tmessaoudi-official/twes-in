<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * What a merge of one subject does beyond pointing rows elsewhere, said by the module that owns it in its own
 * `Infrastructure/Records/`: a product's stock, which no row moves, is carried across by movements of its own. Both run
 * inside the merge's, or the undo's, transaction, so a refusal leaves nothing done.
 */
#[AutoconfigureTag('app.record.merge_effects')]
interface MergeEffects
{
    /** The subject whose merges this answers. */
    public function subject(): string;

    /**
     * Before the records merged away are aliased, while they still stand as themselves.
     *
     * @param non-empty-list<Uuid> $mergedIds
     *
     * @throws MergeRefused
     */
    public function merging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, Uuid $actorUserId, \DateTimeImmutable $at): void;

    /**
     * Once every link is pointed back, so the records merged away stand as themselves again.
     *
     * @param non-empty-list<Uuid> $mergedIds
     *
     * @throws MergeRefused
     */
    public function unmerging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, ?Uuid $actorUserId, \DateTimeImmutable $at): void;
}
