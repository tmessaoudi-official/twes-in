<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Module\Customers\Domain\CustomerGroup;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** A customers list exported as a file, with the search, filters and order the screen shows (docs/SPEC.md § 7, row 60). */
final class CustomerExportTest extends ApiTestCase
{
    private const string HEADER = 'number,name,kind,matricule_fiscal,customer_group,default_tax_codes,default_discount_rate,active,email,custom.employees';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $this->em()->persist(CustomerGroup::create($this->company, 'Grossistes', null, $now));
        $this->em()->persist(CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'employees', 'Salariés', CustomFieldType::Number, false, [], 1, $now));
        $this->em()->flush();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'customer.write'], 'member');
        $this->login('sales@twes.local', 'password-1234');
        // A file waits for the person to have proved who they are again (docs/SPEC.md § 7, audit H-b2).
        $this->stepUp('password-1234');
        $this->uploadFile($this->path().'/imports/customers', 'customers.csv', self::HEADER
            ."\nCLI-0001,Carthage Conseil,company,1234567A/B/M/000,Grossistes,TVA19,\"5,5\",non,compta@carthage.tn,12"
            ."\nCLI-0002,Sonia Ben Ali,individual,,,,,,,\n", 'file', ['mode' => 'create', 'dryRun' => '0']);
        self::assertResponseIsSuccessful();
    }

    public function testAllTheCustomersAreWrittenUnderTheColumnsAnImportTakes(): void
    {
        $lines = $this->csv('/exports/customers.csv');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('customers.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        // A byte order mark leads the file, so that Excel reads it as UTF-8.
        $header = array_map(strval(...), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''));
        $this->client->request('GET', $this->path().'/import-templates/customers.csv');
        self::assertSame(str_getcsv(ltrim(trim(explode("\n", $this->content())[0]), "\xEF\xBB\xBF"), escape: ''), $header, 'the export is a file the import reads back');
        self::assertCount(3, $lines, 'the header and the two customers');
        $row = array_combine($header, str_getcsv($lines[1], escape: ''));
        self::assertSame('CLI-0001', $row['number']);
        self::assertSame('Carthage Conseil', $row['name']);
        self::assertSame('company', $row['kind']);
        self::assertSame('1234567A/B/M/000', $row['matricule_fiscal']);
        self::assertSame('Grossistes', $row['customer_group']);
        self::assertSame('TVA19', $row['default_tax_codes']);
        self::assertSame('5.500', $row['default_discount_rate']);
        self::assertSame('no', $row['active']);
        self::assertSame('12', $row['custom.employees']);
    }

    public function testTheSearchTheFiltersAndTheOrderOfTheScreenNarrowAndSortTheFile(): void
    {
        self::assertCount(2, $this->csv('/exports/customers.csv?q=sonia'));
        self::assertCount(2, $this->csv('/exports/customers.csv?isActive=false'), 'only the inactive customer');
        self::assertCount(2, $this->csv('/exports/customers.csv?kind=individual'));
        self::assertCount(1, $this->csv('/exports/customers.csv?q=nobody'), 'the header alone');
        $lines = $this->csv('/exports/customers.csv?order[name]=desc');
        self::assertStringStartsWith('CLI-0002', $lines[1]);
    }

    public function testTheFileHoldsWhatTheListShowsUnderEveryFilter(): void
    {
        foreach ([
            'kind[]=company&kind[]=individual' => 2,
            'taxRegime[]=standard' => 2,
            'taxRegime[]=exempt' => 0,
            'kind[]=individual&isActive=true' => 1,
            'createdAt[to]=2000-01-01' => 0,
        ] as $query => $rows) {
            self::assertCount(1 + $rows, $this->csv('/exports/customers.csv?'.$query), $query);
            $this->getJson($this->path().'/customers?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($rows, $this->jsonPage()['totalItems'], $query);
        }
    }

    public function testAnXlsxIsOfferedToo(): void
    {
        $this->client->request('GET', $this->path().'/exports/customers.xlsx');

        self::assertResponseIsSuccessful();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('PK', $this->content(), 'a zip archive');
    }

    public function testWhoCannotReadCustomersIsAnsweredAsAStranger(): void
    {
        $this->createUser('stock@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), ['product.read'], 'stock');
        $this->login('stock@twes.local', 'password-1234');

        $this->client->request('GET', $this->path().'/exports/customers.csv');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testNothingElseIsExportable(): void
    {
        $this->client->request('GET', $this->path().'/exports/secrets.csv');

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
