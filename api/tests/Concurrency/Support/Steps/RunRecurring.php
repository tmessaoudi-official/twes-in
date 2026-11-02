<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Recurring\Application\RunRecurringInvoices;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

/** The scheduler's pass over the company's recurring invoices, as the worker runs it; how many drafts it made. */
final class RunRecurring implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        return $container->get(RunRecurringInvoices::class)->handle(RacerContext::company($container, $arguments));
    }
}
