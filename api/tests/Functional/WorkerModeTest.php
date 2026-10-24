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
use App\Texts\Application\TextsInForce;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\ValueObject\TextAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    /** A company's wording read for one request's mail or document is read again for the next, never kept from it. */
    public function testTheTextsReadForOneRequestAreReadAgainForTheNext(): void
    {
        $this->client->disableReboot();
        $company = $this->createCompany('Acme');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new TextOverride($company, new TextAddress('fr', 'pdf', 'invoice.title'), 'Facture de vente', 'Facture', new \DateTimeImmutable()));
        $em->flush();
        // The kernel resets its services as the next request begins, once it has served one: as a worker does.
        $this->client->request('GET', '/api/health');
        $title = static fn (): string => static::getContainer()->get(TranslatorInterface::class)->trans('invoice.title', [], 'pdf', 'fr');
        self::assertSame('Facture de vente', static::getContainer()->get(TextsInForce::class)->for($company->getId(), $title));

        $em->getConnection()->executeStatement("UPDATE text_override SET value = 'Facture client' WHERE company_id = ?", [$company->getId()->toRfc4122()]);
        $this->client->request('GET', '/api/health');

        self::assertSame('Facture client', static::getContainer()->get(TextsInForce::class)->for($company->getId(), $title), 'a wording outlived its request');
    }
}
