<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Shared\Infrastructure\Doctrine\Rows;
use App\Shared\Infrastructure\Submissions\HeldClaim;
use App\Shared\Infrastructure\Submissions\SubmissionClaims;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * One request asking for the `Idempotency-Key` `key` of `account`, as `SubmissionGuard` asks before the work: `won` when
 * it holds the key now, `held` when another request does.
 */
final class ClaimSubmissionKey implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $now = new \DateTimeImmutable();
        $held = $container->get(SubmissionClaims::class)->claim(
            new HeldClaim(Uuid::fromString(Rows::text($arguments['account'] ?? null)), Uuid::fromString(Rows::text($arguments['key'] ?? null)), Uuid::v7()),
            Uuid::fromString(Rows::text($arguments['company'] ?? null)),
            'the same body',
            $now,
            $now->modify('-1 hour'),
        );

        return null === $held ? 'won' : 'held';
    }
}
