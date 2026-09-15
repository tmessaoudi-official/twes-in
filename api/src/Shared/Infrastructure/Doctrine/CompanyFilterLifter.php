<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The company filter lasts as long as the request that turned it on. The API runs PHP in classic mode, where each
 * request has its own entity manager anyway; the test client does not, and worker mode would not either.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: -1024)]
final readonly class CompanyFilterLifter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(): void
    {
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled(CompanyFilter::NAME)) {
            $filters->disable(CompanyFilter::NAME);
        }
    }
}
