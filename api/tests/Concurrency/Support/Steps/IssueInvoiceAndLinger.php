<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Shared\Application\Transactions;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * Racer i issues the invoice `documents[i]` inside a unit of work of its own that stays open `lingerMs` after the issue
 * returns, as a request still writing after it: whatever the issue decided is read by the other racer only once this one
 * commits, so a decision that does not wait for it is caught every time, not when the timing happens to fall that way.
 */
final class IssueInvoiceAndLinger implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $company = RacerContext::company($container, $arguments);
        $document = Uuid::fromString(RacerContext::mine($arguments, 'documents', $index));
        $actor = Uuid::fromString(Rows::text($arguments['actor'] ?? null));
        $linger = $arguments['lingerMs'] ?? null;

        return $container->get(Transactions::class)->run(static function () use ($container, $company, $document, $actor, $linger): ?string {
            $number = $container->get(InvoiceWorkflow::class)->issue($company, $document, $actor)->getNumber();
            usleep(1000 * (\is_int($linger) ? $linger : 0));

            return $number;
        });
    }
}
