<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\DataHealth;

use App\DataHealth\Application\DeclaresDataChecks;
use App\DataHealth\Infrastructure\SeriesGaps;
use App\DataHealth\Infrastructure\UnrecordedDocuments;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteWorkflow;

/**
 * What the sent quotes must still say: their numbers run without a gap in each series and period, and the journal
 * recorded each sending. A quote stores no totals, which its lines give on every read.
 */
final readonly class QuoteDataChecks implements DeclaresDataChecks
{
    public const string NUMBERING = 'quotes.numbering';
    public const string JOURNAL = 'quotes.journal';

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
            self::NUMBERING => $this->series->findings(self::NUMBERING, \sprintf("SELECT company_id, establishment_id, '%s' AS document_type, number, issue_date, id FROM quote", QuoteWorkflow::DOCUMENT_TYPE)),
            self::JOURNAL => $this->unrecorded->findings(self::JOURNAL, 'SELECT company_id, id, number FROM quote', ManageQuotes::ENTITY_TYPE, QuoteWorkflow::SENT),
            default => throw new \LogicException(\sprintf('No quote data check is named %s.', $check)),
        };
    }
}
