<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\DataHealth;

use App\DataHealth\Application\DeclaresDataChecks;
use App\DataHealth\Infrastructure\SeriesGaps;
use App\DataHealth\Infrastructure\UnrecordedDocuments;
use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;

/**
 * What the validated delivery notes must still say: their numbers run without a gap in each series and period, and the
 * journal recorded each validation. A note stores no figures, which its lines give on every read.
 */
final readonly class DeliveryNoteDataChecks implements DeclaresDataChecks
{
    public const string NUMBERING = 'delivery_notes.numbering';
    public const string JOURNAL = 'delivery_notes.journal';

    public function __construct(private SeriesGaps $series, private UnrecordedDocuments $unrecorded)
    {
    }

    public function dataChecks(): array
    {
        return [self::NUMBERING, self::JOURNAL];
    }

    public function findings(string $check): iterable
    {
        return match ($check) {
            self::NUMBERING => $this->series->findings(self::NUMBERING, \sprintf("SELECT company_id, establishment_id, '%s' AS document_type, number, issue_date, id FROM delivery_note", DeliveryNoteWorkflow::DOCUMENT_TYPE)),
            self::JOURNAL => $this->unrecorded->findings(self::JOURNAL, 'SELECT company_id, id, number FROM delivery_note', ManageDeliveryNotes::ENTITY_TYPE, DeliveryNoteWorkflow::VALIDATED),
            default => throw new \LogicException(\sprintf('No delivery note data check is named %s.', $check)),
        };
    }
}
