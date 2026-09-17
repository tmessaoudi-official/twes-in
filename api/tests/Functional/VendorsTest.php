<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

final class VendorsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testTheOptionsSayWhatTheVendorFormAsksFor(): void
    {
        $this->signedIn(['vendor.read']);

        $this->getJson($this->companyPath().'/vendor-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame('TN', $options['countryCode']);
        $identifier = $this->arrayAt($options, 'identifiers')[0];
        self::assertIsArray($identifier);
        self::assertSame(['matricule_fiscal', '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$'], [$identifier['key'], $identifier['pattern']]);
    }

    public function testAWriterAddsAVendorWithoutRegistrationNumbers(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->postJson($this->path(), $this->vendor());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame('FRN-0001', $created['number']);
        self::assertEquals([], $created['identifiers'], 'a domestic supplier need not carry the preset’s numbers');
        self::assertSame('TN', $created['countryCode'], 'an address without a country is in the company’s');
        self::assertSame('TN5910006035183598478831', $created['iban']);
        self::assertSame('STBKTNTT', $created['bic']);
        self::assertSame(30, $created['paymentTermsDays']);
        self::assertTrue($created['isActive']);

        $this->getJson($this->path($this->stringAt($created, 'id')));
        self::assertResponseIsSuccessful();
        self::assertSame('Sotumag', $this->json()['name']);
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'vendor.created'"));
    }

    public function testWhatTheShapeOrThePresetRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        foreach ([
            'number' => ['number' => '-FRN'],
            'name' => ['name' => ''],
            'identifiers.matricule_fiscal' => ['identifiers' => ['matricule_fiscal' => '123']],
            'identifiers.siret' => ['identifiers' => ['siret' => '12345678900011']],
            'iban' => ['iban' => 'TN00 1234'],
            'bic' => ['bic' => 'NOPE'],
            'paymentTermsDays' => ['paymentTermsDays' => 400],
            'countryCode' => ['countryCode' => 'XX'],
            'email' => ['email' => 'not-an-email'],
        ] as $field => $change) {
            $this->postJson($this->path(), $this->vendor($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent(), $field);
        }
        $this->getJson($this->path());
        self::assertCount(0, $this->jsonList());
    }

    public function testANumberAnotherVendorHasAnswersConflict(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());

        $this->postJson($this->path(), $this->vendor(['name' => 'Autre']));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChanged(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->vendor(['email' => 'compta@sotumag.tn', 'city' => 'Sfax', 'isActive' => false]));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['isActive']);
        self::assertSame('Sfax', $this->json()['city']);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'vendor.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['email', 'address', 'isActive']], json_decode($changes, true));
    }

    public function testAReaderOnlyReadsAndSomeoneSignedOutReadsNothing(): void
    {
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['vendor.read'], 'reader');
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());
        $mine = $this->stringAt($this->json(), 'id');

        $this->sendJson('POST', '/api/auth/logout');
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($mine));
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path($mine), $this->vendor());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), $this->vendor(['number' => 'FRN-0002']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysVendorIsNotFoundThroughThisCompany(): void
    {
        $globex = $this->createCompany('Globex');
        $theirs = Vendor::create($globex, 'FRN-0001', new VendorProfile('Globex Supply'), new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path($theirs->getId()->toRfc4122()), $this->vendor());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/vendors');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListIsAPageSearchedNarrowedAndSortedByTheApi(): void
    {
        $now = new \DateTimeImmutable();
        foreach (range(1, 27) as $n) {
            $this->em()->persist(Vendor::create($this->company, \sprintf('FRN-%04d', $n), new VendorProfile('Fournisseur '.$n), $now));
        }
        $this->em()->persist(Vendor::create($this->company, 'FRN-0100', new VendorProfile('Société Générale d’Emballage', identifiers: ['matricule_fiscal' => '7654321B/A/M/000'], email: 'achats@sge.tn', address: new PostalAddress('4, rue de Marseille', null, '1000', 'Tunis'), paymentTermsDays: 60), $now));
        $retired = Vendor::create($this->company, 'FRN-0101', new VendorProfile('Zitouna Bureautique', paymentTermsDays: 15), $now);
        $retired->revise('FRN-0101', new VendorProfile('Zitouna Bureautique', paymentTermsDays: 15), false, $now);
        $this->em()->persist($retired);
        $this->em()->flush();
        $this->signedIn(['vendor.read']);

        $this->getJson($this->path());
        self::assertCount(25, $this->jsonList());
        self::assertSame(29, $this->jsonPage()['totalItems']);

        foreach ([
            'q=generale' => ['FRN-0100'],
            'q=marseille' => ['FRN-0100'],
            'q=7654321' => ['FRN-0100'],
            'q=sge.tn' => ['FRN-0100'],
            'q=fr' => [],
            'isActive=false' => ['FRN-0101'],
            'order[paymentTermsDays]=desc&itemsPerPage=2' => ['FRN-0100', 'FRN-0101'],
            'order[name]=desc&itemsPerPage=1' => ['FRN-0101'],
            'order[city]=asc&itemsPerPage=1' => ['FRN-0100'],
        ] as $query => $numbers) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($numbers, array_column($this->jsonList(), 'number'), $query);
        }
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function vendor(array $changes = []): array
    {
        return [...[
            'number' => 'FRN-0001',
            'name' => 'Sotumag',
            'identifiers' => [],
            'addressLine1' => 'Zone industrielle',
            'city' => 'Ben Arous',
            'iban' => 'tn59 1000 6035 1835 9847 8831',
            'bic' => 'stbktntt',
            'paymentTermsDays' => 30,
            'isActive' => true,
        ], ...$changes];
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('buyer@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('buyer@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $vendorId = null): string
    {
        return $this->companyPath().'/vendors'.(null === $vendorId ? '' : '/'.$vendorId);
    }
}
