<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional\Texts;

use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every text a writer may word otherwise, found by what it says or by its key, each with the original shipped, the
 * installation's wording and the company's, the version of the writer's level, and whether the original changed since
 * the writer's own was written. A locked text is never listed.
 */
final class TextCatalogueTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testATextIsFoundByWhatItSaysOrItsKeyWithEachLevelsWording(): void
    {
        $shop = $this->worded();
        $catalogue = "/api/companies/{$shop->getId()}/texts/fr/catalogue";

        $this->getJson($catalogue.'?domain=pdf&q=facture%20de%20vente');
        self::assertResponseIsSuccessful();
        self::assertSame([[
            'domain' => 'pdf',
            'key' => 'invoice.title',
            'original' => 'Facture',
            'installation' => 'Facture de vente',
            'company' => 'Facture client',
            'version' => 1,
            'originalChanged' => false,
        ]], $this->json()['items'] ?? null);

        $this->getJson($catalogue.'?q=VERIFICATION%20en%20deux');
        $found = $this->found();
        self::assertContains('screen:two-factor auth.two_factor.title', $found, 'accents and case aside, in a page\'s own file');

        $this->getJson($catalogue.'?q=auth.mfa.passkey_elsewhere');
        self::assertSame(['screen auth.mfa.passkey_elsewhere'], $this->found(), 'by its key');

        $this->getJson($catalogue.'?domain=pdf&q=total');
        self::assertNotContains('pdf totals.total', $this->found(), 'a locked text is never offered');
        self::assertContains('pdf totals.amount_due', $this->found());
        $this->getJson($catalogue.'?domain=fiscal');
        self::assertSame(0, $this->json()['total'] ?? null, 'nor a fiscal one');

        $this->getJson($catalogue.'?domain=pdf&itemsPerPage=4');
        $firstFour = $this->found();
        $this->getJson($catalogue.'?domain=pdf&itemsPerPage=2&page=2');
        self::assertSame(\array_slice($firstFour, 2), $this->found(), 'the second page of two is the third and fourth');
        self::assertSame(2, $this->json()['page'] ?? null);
        self::assertGreaterThan(4, $this->json()['total'] ?? 0);
        $this->getJson($catalogue.'?itemsPerPage=100000');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_query', $this->json()['error'] ?? null);
        $this->getJson("/api/companies/{$shop->getId()}/texts/de/catalogue");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnOriginalAReleaseChangedIsFlaggedForTheLevelThatWordedIt(): void
    {
        $shop = $this->worded();
        // A release since: the shop's text was written over another original.
        $this->em()->getConnection()->executeStatement("UPDATE text_override SET original = 'Facture (ancienne)' WHERE company_id = ? AND text_key = 'invoice.title'", [$shop->getId()->toRfc4122()]);

        $this->getJson("/api/companies/{$shop->getId()}/texts/fr/catalogue?changed=1");
        self::assertResponseIsSuccessful();
        self::assertSame(['pdf invoice.title'], $this->found());
        self::assertTrue($this->boolAt($this->firstItem(), 'originalChanged'));

        $this->login('operator@twes.local', self::PASSWORD);
        $this->getJson('/api/platform/texts/fr/catalogue?changed=1');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->found(), 'the installation\'s own was written over today\'s original');
        $this->getJson('/api/platform/texts/fr/catalogue?domain=pdf&q=invoice.title');
        self::assertSame(['domain' => 'pdf', 'key' => 'invoice.title', 'original' => 'Facture', 'installation' => 'Facture de vente', 'company' => null, 'version' => 1, 'originalChanged' => false], $this->firstItem());

        // « Garder le mien »: the same words written again over today's original.
        $this->login('owner@shop.tn', self::PASSWORD);
        $this->sendJson('PUT', "/api/companies/{$shop->getId()}/texts/fr/pdf/invoice.title", ['value' => 'Facture client', 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(['value' => 'Facture client', 'original' => 'Facture', 'version' => 2], $this->json());
        $this->getJson("/api/companies/{$shop->getId()}/texts/fr/catalogue?changed=1");
        self::assertSame([], $this->found());
    }

    public function testOnlyAWriterReadsTheCatalogue(): void
    {
        $shop = $this->worded();
        $this->login('clerk@shop.tn', self::PASSWORD);
        $this->getJson("/api/companies/{$shop->getId()}/texts/fr/catalogue");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/platform/texts/fr/catalogue');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** The installation and a shop each word the invoice's title, the shop's owner signed in; a clerk of the shop reads only. */
    private function worded(): Company
    {
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);
        $this->login('operator@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', '/api/platform/texts/fr/pdf/invoice.title', ['value' => 'Facture de vente', 'version' => 0]);
        self::assertResponseIsSuccessful();

        $shop = $this->createCompany('Quincaillerie');
        $this->createUser('owner@shop.tn', self::PASSWORD, $shop);
        $this->createUser('clerk@shop.tn', self::PASSWORD, $shop, ['company.read'], Role::MEMBER);
        $this->login('owner@shop.tn', self::PASSWORD);
        $this->sendJson('PUT', "/api/companies/{$shop->getId()}/texts/fr/pdf/invoice.title", ['value' => 'Facture client', 'version' => 0]);
        self::assertResponseIsSuccessful();

        return $shop;
    }

    /** @return array<string, mixed> */
    private function firstItem(): array
    {
        return $this->items()[0] ?? self::fail('No text found.');
    }

    /** @return list<string> each item as « domain key » */
    private function found(): array
    {
        return array_map(fn (array $item): string => $this->stringAt($item, 'domain').' '.$this->stringAt($item, 'key'), $this->items());
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        $items = [];
        foreach ($this->arrayAt($this->json(), 'items') as $item) {
            self::assertIsArray($item);
            $keyed = [];
            foreach ($item as $key => $value) {
                $keyed[(string) $key] = $value;
            }
            $items[] = $keyed;
        }

        return $items;
    }
}
