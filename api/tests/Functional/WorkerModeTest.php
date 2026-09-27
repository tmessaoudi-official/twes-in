<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Doctrine\CompanyFilter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FrankenPHP's worker mode (infra/api/Dockerfile) keeps one kernel for many requests, as this client does once it no
 * longer reboots: what one request set up for the company it acted for must be gone before the next one begins,
 * whoever makes it.
 */
final class WorkerModeTest extends ApiTestCase
{
    public function testTheCompanyScopingOfOneRequestDoesNotReachTheNext(): void
    {
        $this->client->disableReboot();
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->client->request('GET', '/api/companies/'.$company->getId()->toRfc4122().'/customers');
        self::assertResponseIsSuccessful();

        $filters = static::getContainer()->get(EntityManagerInterface::class)->getFilters();
        self::assertFalse($filters->isEnabled(CompanyFilter::NAME), 'the company filter outlived its request');
    }
}
