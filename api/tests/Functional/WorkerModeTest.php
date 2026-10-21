<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Application\Effect;
use App\Shared\Infrastructure\Doctrine\CompanyFilter;
use App\Shared\Infrastructure\Effects\EffectsAnswer;
use App\Shared\Infrastructure\Effects\StagedEffects;
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

    /** What one request did beyond its subject is said in its own answer, never in the next person's. */
    public function testTheEffectsOfOneRequestAreNotSaidInTheNext(): void
    {
        $this->client->disableReboot();
        $this->client->request('GET', '/api/health');
        static::getContainer()->get(StagedEffects::class)->said(new Effect('product.price_followed', null));

        $this->client->request('GET', '/api/health');

        self::assertFalse($this->client->getResponse()->headers->has(EffectsAnswer::HEADER), 'an effect outlived its request');
    }
}
