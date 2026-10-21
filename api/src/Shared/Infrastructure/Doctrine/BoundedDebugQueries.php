<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Query;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The queries a request keeps for the profiler, development only, up to a number: Doctrine's holder keeps every one with
 * its parameters, and a request whose queries grow with a file (an import of 4,000 rows) ran out of memory with them and
 * the log records (BoundedDebugLogs), where production, which keeps neither, peaks at half the limit. The profiler still
 * lists the first ones.
 */
#[AsDecorator('doctrine.debug_data_holder', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class BoundedDebugQueries extends DebugDataHolder implements ResetInterface
{
    private int $kept = 0;

    public function __construct(
        private readonly DebugDataHolder $inner,
        #[Autowire(param: 'app.debug.queries_kept')]
        private readonly int $limit,
    ) {
    }

    public function addQuery(string $connectionName, Query $query): void
    {
        if ($this->kept >= $this->limit) {
            return;
        }
        ++$this->kept;
        $this->inner->addQuery($connectionName, $query);
    }

    /** @return array<string, array<string, mixed>[]> each connection's queries, as Doctrine's own holder describes them */
    public function getData(): array
    {
        /** @var array<string, array<string, mixed>[]> $data */
        $data = $this->inner->getData();

        return $data;
    }

    public function reset(): void
    {
        $this->kept = 0;
        $this->inner->reset();
    }
}
