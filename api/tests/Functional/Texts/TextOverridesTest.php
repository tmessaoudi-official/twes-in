<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional\Texts;

use App\Tenancy\Domain\Model\Role;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The texts an operator words for the whole installation, behind a step-up, and a company's owner for the company,
 * whose own win there; read by anyone for the installation's screens, by a member for the company's. A key with dots and
 * a page's part (`screen:two-factor`) travel in the path as they are.
 */
final class TextOverridesTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';
    private const string PART = '/fr/screen:two-factor/auth.two_factor.title';

    public function testAnOperatorWordsTheInstallationsTextBehindAStepUpAndAnyoneReadsIt(): void
    {
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);
        $this->login('operator@twes.local', self::PASSWORD);

        $this->sendJson('PUT', '/api/platform/texts'.self::PART, ['value' => 'Double vérification', 'version' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);
        $this->sendJson('DELETE', '/api/platform/texts'.self::PART);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', '/api/platform/texts'.self::PART, ['value' => 'Double vérification', 'version' => 0]);
        self::assertResponseIsSuccessful();
        self::assertSame(['value' => 'Double vérification', 'original' => 'Vérification en deux étapes', 'version' => 1], $this->json());

        $this->sendJson('PUT', '/api/platform/texts/fr/pdf/invoice.title', ['value' => 'Facture de vente', 'version' => 0]);
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->getJson('/api/texts/fr');
        self::assertResponseIsSuccessful();
        self::assertSame(['screen:two-factor' => ['auth.two_factor.title' => 'Double vérification']], $this->json(), 'the screens\' texts alone: a printed label may carry a bank account');
        $cache = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache);
        self::assertStringContainsString('no-cache', $cache);
        $etag = (string) $this->client->getResponse()->getEtag();
        self::assertNotSame('', $etag);
        self::assertNull($this->client->getCookieJar()->get(self::SESSION_COOKIE), 'an anonymous read opens no session');

        $this->client->request('GET', '/api/texts/fr', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);

        $this->getJson('/api/texts/de');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanysOwnerWordsTheCompanysOwnWhichWinsThereAloneAndAMemberReadsThem(): void
    {
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);
        $this->login('operator@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', '/api/platform/texts'.self::PART, ['value' => 'Double vérification', 'version' => 0]);
        $this->sendJson('PUT', '/api/platform/texts/fr/screen/auth.mfa.passkey_elsewhere', ['value' => 'Votre clé marche à {{address}}.', 'version' => 0]);
        self::assertResponseIsSuccessful();

        $shop = $this->createCompany('Quincaillerie');
        $other = $this->createCompany('Atelier');
        $this->createUser('owner@shop.tn', self::PASSWORD, $shop);
        $this->createUser('clerk@shop.tn', self::PASSWORD, $shop, ['company.read'], Role::MEMBER);
        $this->createUser('owner@other.tn', self::PASSWORD, $other);

        $this->login('owner@shop.tn', self::PASSWORD);
        $this->sendJson('PUT', "/api/companies/{$shop->getId()}/texts".self::PART, ['value' => 'Code de la boutique', 'version' => 0]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version'] ?? null);

        $this->login('clerk@shop.tn', self::PASSWORD);
        $this->getJson("/api/companies/{$shop->getId()}/texts/fr");
        self::assertResponseIsSuccessful();
        self::assertSame([
            'screen' => ['auth.mfa.passkey_elsewhere' => 'Votre clé marche à {{address}}.'],
            'screen:two-factor' => ['auth.two_factor.title' => 'Code de la boutique'],
        ], $this->json());
        $this->sendJson('PUT', "/api/companies/{$shop->getId()}/texts".self::PART, ['value' => 'Autre', 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a member without company.texts writes none, and meets the 404 a stranger does');
        $this->sendJson('DELETE', "/api/companies/{$shop->getId()}/texts".self::PART);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor takes one back');

        $this->login('owner@other.tn', self::PASSWORD);
        $this->getJson("/api/companies/{$other->getId()}/texts/fr");
        self::assertSame(['auth.two_factor.title' => 'Double vérification'], $this->json()['screen:two-factor'] ?? null, 'another company keeps the installation\'s');
        $this->getJson("/api/companies/{$shop->getId()}/texts/fr");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a stranger meets the 404 of a company that is not there');
    }

    public function testEachRefusalIsSaidWithItsReasonAndASaveMadeSinceIsAConflict(): void
    {
        $shop = $this->createCompany('Quincaillerie');
        $this->createUser('owner@shop.tn', self::PASSWORD, $shop);
        $this->login('owner@shop.tn', self::PASSWORD);
        $texts = "/api/companies/{$shop->getId()}/texts";

        foreach ([
            '/fr/fiscal/fiscal.identifier.tn.matricule_fiscal' => ['Matricule', 'locked'],
            '/fr/pdf/totals.total' => ['Montant', 'locked'],
            '/fr/screen/auth.mfa.nowhere' => ['Texte', 'unknown_text'],
            '/de/pdf/invoice.title' => ['Rechnung', 'language'],
            '/fr/screen/auth.mfa.passkey_elsewhere' => ['Utilisez le code.', 'placeholders'],
            '/fr/pdf/invoice.title' => [' ', 'empty'],
        ] as $path => [$value, $reason]) {
            $this->sendJson('PUT', $texts.$path, ['value' => $value, 'version' => 0]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $path);
            self::assertSame($reason, $this->json()['error'] ?? null, $path);
        }
        $this->sendJson('PUT', $texts.'/fr/pdf/invoice.title', ['value' => 'Facture']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a change says which version it read');
        self::assertSame('invalid_body', $this->json()['error'] ?? null);

        $this->sendJson('PUT', $texts.'/fr/pdf/invoice.title', ['value' => 'Facture de vente', 'version' => 0]);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $texts.'/fr/pdf/invoice.title', ['value' => 'Facture client', 'version' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type'] ?? null);

        $this->sendJson('DELETE', $texts.'/fr/pdf/invoice.title');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->sendJson('PUT', $texts.'/fr/pdf/invoice.title', ['value' => 'Facture client', 'version' => 0]);
        self::assertResponseIsSuccessful('the reset took the text back: a first one is written again');
    }

    public function testNoOneButAnOperatorWordsTheInstallationsTexts(): void
    {
        $shop = $this->createCompany('Quincaillerie');
        $this->createUser('owner@shop.tn', self::PASSWORD, $shop);
        $this->login('owner@shop.tn', self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->sendJson('PUT', '/api/platform/texts/fr/pdf/invoice.title', ['value' => 'Facture de vente', 'version' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->sendJson('DELETE', '/api/platform/texts/fr/pdf/invoice.title');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
