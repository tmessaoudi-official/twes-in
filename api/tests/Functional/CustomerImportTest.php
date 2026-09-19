<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\Domain\AuditLog;
use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroup;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Infrastructure\Spreadsheet\OpenSpoutWriter;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** Customers imported from a file (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19). */
final class CustomerImportTest extends ApiTestCase
{
    private const string MATRICULE = '1234567A/B/M/000';
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
    }

    public function testAPreviewSaysWhatWouldHappenAndStoresNothing(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import($this->twoCustomers(), dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'rejected' => []], $this->json());
        self::assertSame(0, $this->customers());
        self::assertSame(0, $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action = 'customer.created'"));
        $this->getJson($this->companyPath().'/customers');
        self::assertResponseIsSuccessful('the session outlives a preview');
    }

    public function testAnImportCreatesEveryRowUnderTheRulesOfTheCustomerForm(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import($this->twoCustomers());

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2, 3], 'updated' => [], 'rejected' => []], $this->json());
        $business = $this->customer('CLI-0001');
        self::assertSame('Carthage Conseil', $business->getProfile()->name);
        self::assertSame(['matricule_fiscal' => self::MATRICULE], $business->getProfile()->identifiers);
        self::assertSame('Grossistes', $business->getGroup()?->getName());
        self::assertSame([$this->taxId('TVA19')], $business->getDefaultTaxComponentIds());
        self::assertSame('5.500', $business->getProfile()->defaultDiscountRate, 'a decimal comma is read as a point');
        self::assertFalse($business->isActive());
        self::assertSame(['employees' => 12], $business->getCustomFields(), 'a number field gets a number, not text');
        self::assertSame('TN', $business->getProfile()->billingAddress->countryCode, 'an address without a country is in the company’s');
        $person = $this->customer('CLI-0002');
        self::assertSame('individual', $person->getProfile()->kind->value);
        self::assertTrue($person->isActive());
        self::assertSame('standard', $person->getTaxRegime()->getCode());
        self::assertSame(2, $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action = 'customer.created'"));
    }

    /**
     * Every flush walks every entity the unit of work manages, so a file whose written rows stay managed costs the
     * square of its length: a thousand rows ran past PHP's thirty seconds (docs/SPEC.md § 7, 2026-09-19).
     */
    public function testWhatARowWroteIsNoLongerManagedOnceItIsWritten(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import($this->twoCustomers());

        self::assertResponseIsSuccessful();
        $this->assertNothingImportedIsManaged();
    }

    /** A profiled import of 2000 rows keeps every query and ran out of memory saving the profile after its answer. */
    public function testAnImportIsNeverProfiled(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->client->enableProfiler();

        $this->import($this->twoCustomers(), dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getProfile(), 'no profile was stored');
    }

    public function testOneRejectedRowStoresNothingAndNamesItsLineAndColumn(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import(self::HEADER."\nCLI-0001,Carthage Conseil,company,".self::MATRICULE.",,,,,,\n\nCLI-0002,Sonia Ben Ali,individual,,Inconnus,,,,,\n");

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $report = $this->json();
        self::assertFalse($report['committed']);
        self::assertSame([2], $report['created'], 'what the valid rows would have done');
        self::assertSame(
            [['line' => 4, 'column' => 'customer_group', 'code' => 'unknown_group', 'params' => ['name' => 'Inconnus'], 'message' => 'The company has no customer group named "Inconnus". Create it first.']],
            $report['rejected'],
            'the line the author sees, the empty one counted',
        );
        self::assertSame(0, $this->customers(), 'the valid row was not stored either');
    }

    public function testThePresetsRuleRejectsTheRowOnTheIdentifiersColumn(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import(self::HEADER."\nCLI-0001,Carthage Conseil,company,,,,,,,\n", dryRun: true);

        self::assertResponseIsSuccessful();
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame('matricule_fiscal', $rejected[0]['column'] ?? null);
        self::assertSame(['identifier_required', ['identifier' => 'matricule_fiscal']], [$rejected[0]['code'] ?? null, $rejected[0]['params'] ?? null]);
    }

    /**
     * A refusal reaches the person in their language through a stable code and its parameters, whichever rule made
     * it: the importer's own, the customer form's, a custom field's, or the file repeating a number (docs/SPEC.md
     * § 7, 2026-09-19).
     */
    public function testEveryRejectedRowCarriesACodeAndItsParameters(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import(
            "number,name,kind,tax_regime_code,default_tax_codes,default_discount_rate,active,custom.employees\n"
            .",Sans numéro,individual,,,,,\n"
            ."CLI-0003,Robot,robot,,,,,\n"
            ."CLI-0004,Régime,individual,exotique,,,,\n"
            ."CLI-0005,Taxe,individual,,XX,,,\n"
            ."CLI-0006,Remise,individual,,,150,,\n"
            ."CLI-0007,Actif,individual,,,,peut-être,\n"
            ."CLI-0008,Salariés,individual,,,,,beaucoup\n"
            ."CLI-0003,Double,individual,,,,,\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        $rejected = array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [],
            $this->arrayAt($this->json(), 'rejected'),
        );
        self::assertSame([
            [2, 'number', 'value_required', []],
            [3, 'kind', 'not_one_of', ['choices' => 'company, individual']],
            [4, 'tax_regime_code', 'unknown_tax_regime', ['code' => 'exotique']],
            [5, 'default_tax_codes', 'unknown_tax_code', ['code' => 'XX']],
            [6, 'default_discount_rate', 'invalid_rate', []],
            [7, 'active', 'not_yes_or_no', []],
            [8, 'custom.employees', 'not_a_number', []],
            [9, 'number', 'duplicate_in_file', ['line' => 3]],
        ], $rejected);
    }

    public function testCreateModeRefusesAKnownNumberAndUpsertFillsInOnlyTheCellsTheRowHas(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->import(self::HEADER."\nCLI-0001,Carthage Conseil,company,".self::MATRICULE.",Grossistes,,,,compta@carthage.tn,12\n");
        self::assertResponseIsSuccessful();
        $second = "number,name,email,custom.employees\nCLI-0001,Carthage Conseil Group,,\n";

        $this->import($second, dryRun: true);
        self::assertSame([['line' => 2, 'column' => 'number', 'code' => 'already_exists', 'params' => [], 'message' => 'A customer already has this number. Import in "create and update" mode to update it.']], $this->json()['rejected']);

        $this->import($second, mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'rejected' => []], $this->json());
        $this->assertNothingImportedIsManaged();
        $this->em()->clear();
        $updated = $this->customer('CLI-0001');
        self::assertSame('Carthage Conseil Group', $updated->getProfile()->name);
        self::assertSame('compta@carthage.tn', $updated->getProfile()->email, 'a blank cell keeps what is there');
        self::assertSame('Grossistes', $updated->getGroup()?->getName(), 'a column the file lacks keeps what is there');
        self::assertSame(['employees' => 12], $updated->getCustomFields());
        self::assertSame(['matricule_fiscal' => self::MATRICULE], $updated->getProfile()->identifiers);
    }

    public function testAFileWithAColumnNobodyDeclaresIsRefusedWhole(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import("number,name,colour\nCLI-0001,Carthage Conseil,red\n");

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['error' => 'unknown_columns', 'columns' => ['colour'], 'limit' => null], $this->json());
    }

    public function testAnXlsxFileIsReadTheSameWay(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $path = (string) tempnam(sys_get_temp_dir(), 'import-test-');
        (new OpenSpoutWriter())->write($path, SpreadsheetFormat::Xlsx, [['number', 'name', 'kind'], ['CLI-0009', 'Sonia Ben Ali', 'individual']]);
        $contents = (string) file_get_contents($path);
        unlink($path);

        $this->import($contents, name: 'customers.xlsx');

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['created']);
        self::assertSame('Sonia Ben Ali', $this->customer('CLI-0009')->getProfile()->name);
    }

    public function testWhatIsNotASpreadsheetIsRefused(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import('%PDF-1.7', name: 'customers.pdf');
        self::assertSame(['error' => 'invalid_request'], $this->json());

        $this->import('not a zip archive', name: 'customers.xlsx');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('unreadable', $this->json()['error']);
    }

    public function testSomebodyWhoCannotWriteCustomersIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['customer.read']);

        $this->import($this->twoCustomers(), dryRun: true);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function twoCustomers(): string
    {
        return self::HEADER
            ."\nCLI-0001,Carthage Conseil,company,".self::MATRICULE.',Grossistes,TVA19,"5,5",non,compta@carthage.tn,12'
            ."\nCLI-0002,Sonia Ben Ali,individual,,,,,,,\n";
    }

    private function import(string $contents, string $mode = 'create', bool $dryRun = false, string $name = 'customers.csv'): void
    {
        $this->uploadFile($this->companyPath().'/imports/customers', $name, $contents, 'file', ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0']);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    /** Read straight after the request, on the entity manager that served it: the next request reboots the kernel. */
    private function assertNothingImportedIsManaged(): void
    {
        $managed = $this->em()->getUnitOfWork()->getIdentityMap();
        self::assertSame([], $managed[Customer::class] ?? [], 'no customer a row wrote is still managed');
        self::assertSame([], $managed[AuditLog::class] ?? [], 'no audit row is still managed');
    }

    private function customers(): int
    {
        return $this->numberOf('SELECT COUNT(*) FROM customer WHERE company_id = ?', [$this->company->getId()->toRfc4122()]);
    }

    /** @param list<string> $parameters */
    private function numberOf(string $sql, array $parameters = []): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function customer(string $number): Customer
    {
        $customer = $this->em()->getRepository(Customer::class)->findOneBy(['number' => $number]);
        self::assertInstanceOf(Customer::class, $customer);

        return $customer;
    }

    private function taxId(string $code): string
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId()->toRfc4122();
    }
}
