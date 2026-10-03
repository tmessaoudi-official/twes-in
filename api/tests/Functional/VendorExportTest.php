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

/** The vendors list exported as a file, under the columns the import reads (docs/SPEC.md § 7, row 60). */
final class VendorExportTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $this->em()->persist(Vendor::create($this->company, 'FRN-0001', new VendorProfile(
            'Aciers du Sud',
            'Aciers du Sud SARL',
            ['matricule_fiscal' => '1234567A/B/M/000'],
            'compta@aciers.tn',
            null,
            null,
            new PostalAddress('12 rue de la Fonderie', null, '2033', 'Megrine', 'TN'),
            'TN5904018104003691000123',
            null,
            30,
        ), $now));
        $inactive = Vendor::create($this->company, 'FRN-0002', new VendorProfile('Sotumag'), $now);
        $inactive->revise('FRN-0002', $inactive->getProfile(), false, $now);
        $this->em()->persist($inactive);
        $this->em()->flush();
        $this->createUser('buyer@twes.local', 'password-1234', $this->company, ['vendor.read'], 'member');
        $this->login('buyer@twes.local', 'password-1234');
    }

    public function testEveryVendorIsOneRowUnderTheColumnsAnImportTakes(): void
    {
        $lines = $this->csv('/exports/vendors.csv');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('vendors.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $header = array_map(strval(...), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''));
        foreach (['number', 'name', 'legal_name', 'matricule_fiscal', 'email', 'city', 'iban', 'payment_terms_days', 'active'] as $column) {
            self::assertContains($column, $header);
        }
        self::assertCount(3, $lines, 'the header and the two vendors');
        $rows = array_column(array_map(static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')), \array_slice($lines, 1)), null, 'number');
        self::assertSame(
            ['Aciers du Sud', 'Aciers du Sud SARL', '1234567A/B/M/000', 'compta@aciers.tn', '12 rue de la Fonderie', '2033', 'Megrine', 'TN', 'TN5904018104003691000123', '30', 'yes'],
            [$rows['FRN-0001']['name'], $rows['FRN-0001']['legal_name'], $rows['FRN-0001']['matricule_fiscal'], $rows['FRN-0001']['email'], $rows['FRN-0001']['line1'], $rows['FRN-0001']['postal_code'], $rows['FRN-0001']['city'], $rows['FRN-0001']['country_code'], $rows['FRN-0001']['iban'], $rows['FRN-0001']['payment_terms_days'], $rows['FRN-0001']['active']],
        );
        self::assertSame(['Sotumag', '', 'no'], [$rows['FRN-0002']['name'], $rows['FRN-0002']['payment_terms_days'], $rows['FRN-0002']['active']]);
    }

    public function testTheSearchTheFilterAndTheOrderOfTheScreenNarrowAndSortTheFile(): void
    {
        self::assertCount(2, $this->csv('/exports/vendors.csv?q=sotumag'));
        self::assertCount(2, $this->csv('/exports/vendors.csv?isActive=false'), 'only the inactive vendor');
        self::assertCount(1, $this->csv('/exports/vendors.csv?q=nobody-here'), 'the header alone');
        self::assertStringStartsWith('FRN-0002', $this->csv('/exports/vendors.csv?order[number]=desc')[1]);
    }

    public function testAnXlsxIsOfferedToo(): void
    {
        $this->client->request('GET', $this->path().'/exports/vendors.xlsx');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', $this->content(), 'a zip archive');
    }

    public function testWhoCannotReadVendorsIsAnsweredAsAStranger(): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), ['customer.read'], 'sales');
        $this->login('sales@twes.local', 'password-1234');

        $this->client->request('GET', $this->path().'/exports/vendors.csv');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return list<string> */
    private function csv(string $suffix): array
    {
        $this->client->request('GET', $this->path().$suffix);

        return array_values(array_filter(explode("\n", $this->content()), static fn (string $line): bool => '' !== trim($line)));
    }

    private function content(): string
    {
        // A file response is deleted once sent: what the browser got is what the client kept.
        return $this->client->getInternalResponse()->getContent();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
