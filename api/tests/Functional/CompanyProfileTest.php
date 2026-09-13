<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

final class CompanyProfileTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testAReaderSeesTheProfileWithWhatItsPresetAsksFor(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"identifiers":{}', (string) $this->client->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('Acme', $body['name']);
        self::assertSame('TN', $body['countryCode']);
        self::assertSame('standard', $body['vatRegime']);
        self::assertArrayNotHasKey('legalName', $body, 'an empty value is left out, like every null the API answers');
        self::assertFalse($body['writable']);

        $fields = $this->arrayAt($body, 'identifierFields');
        self::assertCount(1, $fields);
        self::assertIsArray($fields[0]);
        self::assertSame('matricule_fiscal', $fields[0]['key']);
        self::assertSame('^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$', $fields[0]['pattern']);
        self::assertTrue($fields[0]['required']);
        self::assertIsString($fields[0]['label']);
        self::assertStringNotContainsString('fiscal.identifier', $fields[0]['label']);

        $regimes = $this->arrayAt($body, 'vatRegimes');
        self::assertCount(1, $regimes);
        self::assertIsArray($regimes[0]);
        self::assertSame('standard', $regimes[0]['code']);
        self::assertIsString($regimes[0]['label']);
        self::assertStringNotContainsString('fiscal.regime', $regimes[0]['label']);
    }

    public function testAnAdministratorRevisesTheProfile(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile([
            'legalName' => 'Acme SARL',
            'iban' => 'tn59 1000 6035 1835 9847 8831',
            'bic' => 'biattntt',
        ]));

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('Acme SARL', $body['legalName']);
        self::assertSame('TN5910006035183598478831', $body['iban']);
        self::assertSame('BIATTNTT', $body['bic']);
        self::assertSame(['matricule_fiscal' => '1234567A/B/M/000'], $body['identifiers']);
        self::assertTrue($body['writable']);

        $this->getJson($this->path());
        self::assertSame('Acme SARL', $this->json()['legalName']);
        $count = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'company.profile_revised'");
        self::assertEquals(1, $count);
    }

    public function testAnIdentifierThatDoesNotHaveThePresetsShapeIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['identifiers' => ['matricule_fiscal' => '1234567']]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnIdentifierThePresetRequiresMayNotBeLeftOut(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['identifiers' => ['matricule_fiscal' => ' ']]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnIdentifierThePresetDoesNotKnowIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['identifiers' => ['matricule_fiscal' => '1234567A/B/M/000', 'siren' => '123456789']]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testARegimeThePresetDoesNotOfferIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['vatRegime' => 'franchise']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAMalformedIbanIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['iban' => 'TN00 1234']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAReaderMayNotReviseTheProfile(): void
    {
        $this->signedIn(['company.read']);

        $this->sendJson('PUT', $this->path(), $this->profile());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyTheCallerHasNothingToDoWithLooksAbsent(): void
    {
        $globex = $this->createCompany('Globex');
        $this->signedIn(['company.read', 'company.settings']);

        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/profile');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function profile(array $changes = []): array
    {
        return [...[
            'legalName' => null,
            'legalForm' => 'SARL',
            'identifiers' => ['matricule_fiscal' => '1234567A/B/M/000'],
            'addressLine1' => '12 rue de Marseille',
            'addressLine2' => null,
            'postalCode' => '1000',
            'city' => 'Tunis',
            'email' => 'billing@acme.tn',
            'phone' => '+216 71 000 000',
            'website' => 'https://acme.tn',
            'iban' => null,
            'bic' => null,
            'vatRegime' => 'standard',
            'invoiceFooterText' => 'Merci de votre confiance.',
            'latePenaltyText' => null,
        ], ...$changes];
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, $permissions, 'admin');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/profile';
    }
}
