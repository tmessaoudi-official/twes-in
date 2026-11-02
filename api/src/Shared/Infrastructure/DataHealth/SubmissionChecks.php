<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The keys sent with writes that add (`submission_claim`): a claim is answered within the longest a request takes, so
 * one still unanswered after it belongs to a request that stopped (made, its answer lost, or never made), and every
 * claim is forgotten once its hours are over, so one kept an hour beyond them says the hourly purge has stopped.
 */
final readonly class SubmissionChecks implements DeclaresDataChecks
{
    public const string STUCK = 'submissions.stuck';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        #[Autowire('%app.long_request.max_seconds%')] private int $longestRequestSeconds,
        #[Autowire('%app.submissions.kept_hours%')] private int $keptHours,
    ) {
    }

    public function dataChecks(): array
    {
        return [self::STUCK];
    }

    public function findings(string $check): iterable
    {
        if (self::STUCK !== $check) {
            throw new \LogicException(\sprintf('No submission data check is named %s.', $check));
        }
        $now = $this->clock->now();
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT company_id::text AS company, CASE
                    WHEN claimed_at < :forgotten THEN 'kept beyond their hours'
                    WHEN work_done THEN 'made, their answer lost'
                    ELSE 'never made'
                END AS stuck, COUNT(*) AS claims
            FROM submission_claim
            WHERE claimed_at < :forgotten OR (status IS NULL AND claimed_at < :stopped)
            GROUP BY 1, 2
            ORDER BY 1, 2
            SQL, [
            'forgotten' => $now->modify(\sprintf('-%d hours', $this->keptHours + 1))->format('Y-m-d H:i:s'),
            'stopped' => $now->modify(\sprintf('-%d seconds', $this->longestRequestSeconds))->format('Y-m-d H:i:s'),
        ]);
        foreach ($rows as $row) {
            $company = Rows::textOrNull($row['company']);
            yield new DataFinding(self::STUCK, null === $company ? null : Uuid::fromString($company), \sprintf('%d submission keys %s', Rows::int($row['claims']), Rows::text($row['stuck'])));
        }
    }
}
