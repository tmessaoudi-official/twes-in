<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

final class CompanyProfileTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme', named: false);
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
        self::assertNull($fields[0]['pattern'], 'a number read part by part is judged by the API alone');
        self::assertSame("Exemple\u{00A0}: 1234567A/B/M/000. Il commence par ses 7 chiffres, même si la carte l’imprime dans l’autre sens.", $fields[0]['hint']);
        self::assertTrue($fields[0]['required']);
        self::assertIsString($fields[0]['label']);
        self::assertStringNotContainsString('fiscal.identifier', $fields[0]['label']);

        $regimes = $this->arrayAt($body, 'vatRegimes');
        self::assertSame(['standard', 'forfaitaire'], array_column($regimes, 'code'));
        foreach (array_column($regimes, 'label') as $label) {
            self::assertIsString($label);
            self::assertStringNotContainsString('fiscal.regime', $label);
        }
    }

    public function testAnAdministratorRevisesTheProfile(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        // The bank details are on the step-up list (docs/SPEC.md § 7); the rest of the profile asks for no proof.
        $this->stepUp('password-1234');

        $this->sendJson('PUT', $this->path(), $this->profile([
            'legalName' => 'Acme SARL',
            'iban' => 'tn59 1000 6035 1835 9847 8831',
            'bic' => 'biattntt',
            // The company's own matricule is read as every holder's: whatever separates its parts, in any case.
            'identifiers' => ['matricule_fiscal' => '1234567.b-b m 000'],
        ]));

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('Acme SARL', $body['legalName']);
        self::assertSame('TN5910006035183598478831', $body['iban']);
        self::assertSame('BIATTNTT', $body['bic']);
        self::assertSame(['matricule_fiscal' => '1234567B/B/M/000'], $body['identifiers']);
        self::assertTrue($body['writable']);

        $this->getJson($this->path());
        self::assertSame('Acme SARL', $this->json()['legalName']);
        $count = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'company.profile_revised'");
        self::assertEquals(1, $count);
    }

    /** The company's phone is read by its own country, kept as E.164, refused with an example, judged once it changes. */
    public function testThePhoneIsReadByTheCompanysCountry(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['phone' => '71 900 868']));
        self::assertResponseIsSuccessful();
        self::assertSame('+21671900868', $this->json()['phone']);

        $this->sendJson('PUT', $this->path(), $this->profile(['phone' => '71 900']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'phone', 'reason' => 'phone_length', 'params' => ['example' => '+216 30 010 123', 'callingCode' => '+216']]], $this->refusals());

        $this->em()->getConnection()->executeStatement("UPDATE company SET phone = '71 900' WHERE id = ?", [$this->company->getId()->toRfc4122()]);
        $this->sendJson('PUT', $this->path(), $this->profile(['phone' => '71 900', 'legalName' => 'Acme SARL']));
        self::assertResponseIsSuccessful();
        self::assertSame(['Acme SARL', '71 900'], [$this->json()['legalName'], $this->json()['phone']]);
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

    public function testATunisianCompanyMaySayItIsUnderTheForfaitaireRegime(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['vatRegime' => 'forfaitaire']));

        self::assertResponseIsSuccessful();
        self::assertSame('forfaitaire', $this->em()->getConnection()->fetchOne('SELECT vat_regime FROM company WHERE id = ?', [$this->company->getId()->toRfc4122()]));
    }

    public function testAMalformedIbanIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path(), $this->profile(['iban' => 'TN00 1234']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testABareRibIsKeptAsTheIbanItSpells(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->stepUp('password-1234');

        $this->sendJson('PUT', $this->path(), $this->profile(['iban' => '10 006 035 1835984788 31']));

        self::assertResponseIsSuccessful();
        self::assertSame('TN5910006035183598478831', $this->em()->getConnection()->fetchOne('SELECT iban FROM company WHERE id = ?', [$this->company->getId()->toRfc4122()]));
    }

    /** The form's rules and the preset's answer one shape, under the reasons a file is refused with. */
    public function testARefusalNamesItsFieldAndAReasonTheScreenTranslates(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        foreach ([
            'email' => [['email' => 'nobody@nowhere'], 'invalid_email', []],
            'legalName' => [['legalName' => str_repeat('a', 201)], 'invalid_legal_name', []],
            'addressLine1' => [['addressLine1' => str_repeat('a', 201)], 'invalid_text', ['max' => 200]],
            'invoiceFooterText' => [['invoiceFooterText' => str_repeat('a', 2001)], 'invalid_text', ['max' => 2000]],
            'iban' => [['iban' => 'TN00 1234'], 'invalid_iban', []],
            'identifiers[matricule_fiscal]' => [['identifiers' => ['matricule_fiscal' => str_repeat('1', 65)]], 'invalid_text', ['max' => 64]],
            'identifiers.matricule_fiscal' => [['identifiers' => ['matricule_fiscal' => '1234567']], 'identifier_part', ['identifier' => 'matricule_fiscal', 'example' => '1234567A/B/M/000', 'part' => 'key']],
            'vatRegime' => [['vatRegime' => 'franchise'], 'unknown_tax_regime', ['code' => 'franchise']],
        ] as $field => [$change, $reason, $params]) {
            $this->sendJson('PUT', $this->path(), $this->profile($change));

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertSame([['propertyPath' => $field, 'reason' => $reason, 'params' => $params]], $this->refusals(), $field);
        }

        // Its check holds, but a Tunisian IBAN is 24 characters: the country's own length is refused under the same reason.
        $this->sendJson('PUT', $this->path(), $this->profile(['iban' => 'TN521000603518359847883']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'iban', 'reason' => 'invalid_iban', 'params' => []]], $this->refusals());
    }

    public function testASaveOfAVersionSavedSinceIsRefusedAsChangedAndWhatWasSavedStays(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->getJson($this->path());
        self::assertSame(1, $this->json()['version']);

        $this->sendJson('PUT', $this->path(), $this->profile(['version' => 1, 'city' => 'Sfax']));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['version']);

        $this->sendJson('PUT', $this->path(), $this->profile(['version' => 1, 'city' => 'Sousse']));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path());
        self::assertSame(['Sfax', 2], [$this->json()['city'], $this->json()['version']]);
    }

    /** The closed period is a setting of its own, yet it moves the version the form is checked against. */
    public function testASwitchSetSinceTheFormWasReadRefusesItsSave(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->sendJson('PUT', $this->path(), $this->profile(['version' => 1]));
        self::assertResponseIsSuccessful();
        $read = $this->json()['version'];

        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/closing', ['closedThrough' => '2026-01-31']);
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->path(), $this->profile(['version' => $read, 'city' => 'Sfax']));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testASaveThatChangesNothingKeepsItsVersion(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->sendJson('PUT', $this->path(), $this->profile());
        $version = $this->json()['version'];

        $this->sendJson('PUT', $this->path(), $this->profile());

        self::assertResponseIsSuccessful();
        self::assertSame($version, $this->json()['version']);
    }

    public function testASaveSaysWhichVersionItRevises(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $profile = $this->profile(['city' => 'Sfax']);
        unset($profile['version']);

        $this->sendJson('PUT', $this->path(), $profile);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
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
            'version' => $this->storedVersion('company', $this->company->getId()->toRfc4122()),
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
