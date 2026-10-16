<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Module\Expenses\Domain\ExpenseCategory;
use App\Module\Vendors\Domain\Vendor;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vendors imported from a file, the third subject after customers and products (docs/SPEC.md § 8 row 59). A vendor
 * names its usual expense category by name, as the file names a unit by its code and a category by its name.
 */
final class VendorImportTest extends ApiTestCase
{
    private const string MATRICULE = '1234567A/B/M/000';
    private const string HEADER = 'number,name,legal_name,matricule_fiscal,email,iban,bic,payment_terms_days,expense_category,active';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->em()->persist(ExpenseCategory::create($this->company, 'Fournitures', null, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    public function testAnImportCreatesEveryRowUnderTheRulesOfTheVendorForm(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->import($this->twoVendors());

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2, 3], 'updated' => [], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        $supplier = $this->vendor('FRN-0001');
        self::assertSame('Aciers du Sud', $supplier->getProfile()->name);
        self::assertSame('Aciers du Sud SARL', $supplier->getProfile()->legalName);
        self::assertSame(['matricule_fiscal' => self::MATRICULE], $supplier->getProfile()->identifiers);
        self::assertSame('TN5910006035183598478831', $supplier->getProfile()->iban);
        self::assertSame(30, $supplier->getProfile()->paymentTermsDays);
        self::assertSame('Fournitures', $this->categoryName($supplier));
        self::assertFalse($supplier->isActive());
        $other = $this->vendor('FRN-0002');
        self::assertSame('Outillage Pro', $other->getProfile()->name);
        self::assertTrue($other->isActive());
        self::assertNull($other->getProfile()->defaultExpenseCategoryId);
    }

    public function testEveryRejectedRowCarriesACodeAndItsParameters(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->import(
            "number,name,matricule_fiscal,payment_terms_days,expense_category,active\n"
            .',Sans numéro,'.self::MATRICULE.",,,\n"
            .'FRN-0003,Délais,'.self::MATRICULE.",bientôt,,\n"
            .'FRN-0004,Rubrique,'.self::MATRICULE.",,Inconnue,\n"
            .'FRN-0005,Actif,'.self::MATRICULE.",,,peut-être\n"
            .'FRN-0003,Double,'.self::MATRICULE.",,,\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        $rejected = array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [],
            $this->arrayAt($this->json(), 'rejected'),
        );
        self::assertSame([
            [2, 'number', 'value_required', []],
            [3, 'payment_terms_days', 'not_a_whole_number', []],
            [4, 'expense_category', 'unknown_expense_category', ['name' => 'Inconnue']],
            [5, 'active', 'not_yes_or_no', []],
            [6, 'number', 'duplicate_in_file', ['line' => 3]],
        ], $rejected);
    }

    /**
     * A file is held to the vendor form's own rules for what the domain does not check: bank details, contact details,
     * the country and every length. A country the column cannot hold was a server error on the preview.
     */
    public function testAFileIsHeldToTheFormsRulesForBankAndContactDetails(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->import(
            "number,name,email,phone,website,country_code,iban,bic,notes\n"
            ."FRN-0010,Courriel,pas-un-courriel,,,,,,\n"
            ."FRN-0011,Téléphone,,appeler le standard,,,,,\n"
            ."FRN-0012,Site,,,pas un site,,,,\n"
            ."FRN-0013,Pays,,,,Tunisie,,,\n"
            ."FRN-0014,Compte,,,,,TN00 1234,,\n"
            ."FRN-0015,Banque,,,,,,PASUNBIC,\n"
            .'FRN-0016,Notes,,,,,,,'.str_repeat('x', 5001)."\n"
            ."FRN-0017,Minuscules,compta@aciers.tn,+216 71 000 000,https://aciers.tn,tn,,,\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        $rejected = array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [],
            $this->arrayAt($this->json(), 'rejected'),
        );
        self::assertSame([
            [2, 'email', 'invalid_email', []],
            [3, 'phone', 'invalid_phone', []],
            [4, 'website', 'invalid_website', []],
            [5, 'country_code', 'invalid_country', []],
            [6, 'iban', 'invalid_iban', []],
            [7, 'bic', 'invalid_bic', []],
            [8, 'notes', 'invalid_text', ['max' => 5000]],
        ], $rejected);
        self::assertSame([9], $this->arrayAt($this->json(), 'created'), 'a country written in small letters is the same country');
    }

    /**
     * A vendor is not required to carry a registration number — the preset requires one of a company and of a business
     * CUSTOMER, not of a supplier — but one written down must have the preset's shape, and its refusal reaches the
     * report under the preset's own stable code rather than a message only an English reader understands.
     */
    public function testAMisshapenRegistrationNumberIsRejectedUnderThePresetsOwnCode(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->import("number,name,matricule_fiscal\nFRN-0006,Sans matricule,\nFRN-0007,Matricule tordu,pas-un-matricule\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->arrayAt($this->json(), 'created'), 'a vendor without one is accepted');
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame(
            ['matricule_fiscal', 'identifier_shape', ['identifier' => 'matricule_fiscal']],
            [$rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null, $rejected[0]['params'] ?? null],
        );
    }

    public function testCreateModeRefusesAKnownNumberAndUpsertFillsInOnlyTheCellsTheRowHas(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->import($this->twoVendors());
        self::assertResponseIsSuccessful();
        $second = "number,name,email\nFRN-0001,Aciers du Sud SA,\n";

        $this->import($second, dryRun: true);
        $refused = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($refused[0] ?? null);
        self::assertSame('already_exists', $refused[0]['code'] ?? null);

        $this->import($second, mode: 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'rejected' => [], 'notes' => [], 'alreadyImportedAt' => null], $this->json());
        $this->em()->clear();
        $updated = $this->vendor('FRN-0001');
        self::assertSame('Aciers du Sud SA', $updated->getProfile()->name);
        self::assertSame('compta@aciers.tn', $updated->getProfile()->email, 'a blank cell keeps what is there');
        self::assertSame(30, $updated->getProfile()->paymentTermsDays, 'a column the file lacks keeps what is there');
        self::assertSame('Fournitures', $this->categoryName($updated));
    }

    public function testWhatTheExportWritesTheImportTakesBackWithTheUsualExpenseCategoryArchivedSince(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write', 'expense.read']);
        $this->import($this->twoVendors());
        self::assertResponseIsSuccessful();
        $category = $this->em()->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Fournitures']);
        self::assertInstanceOf(ExpenseCategory::class, $category);
        $category->revise('Fournitures', null, false, new \DateTimeImmutable());
        $this->em()->flush();

        $this->client->request('GET', $this->companyPath().'/exports/vendors.csv');
        self::assertResponseIsSuccessful();
        $exported = $this->client->getInternalResponse()->getContent();
        $lines = array_values(array_filter(explode("\n", $exported), static fn (string $line): bool => '' !== trim($line)));
        $header = array_map(strval(...), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''));
        $rows = array_column(array_map(static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')), \array_slice($lines, 1)), null, 'number');
        self::assertSame(['Fournitures', ''], [$rows['FRN-0001']['expense_category'] ?? null, $rows['FRN-0002']['expense_category'] ?? null]);

        // The vendor's own category is kept under its name though it is archived now, as the vendor form keeps it.
        $this->import($exported, mode: 'upsert');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['rejected'] ?? null);
        $this->em()->clear();
        self::assertSame('Fournitures', $this->categoryName($this->vendor('FRN-0001')));

        // Another vendor cannot be given an archived category by its name.
        $this->import("number,name,expense_category\nFRN-0002,Outillage Pro,Fournitures\n", mode: 'upsert');
        $refused = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($refused[0] ?? null);
        self::assertSame('unknown_expense_category', $refused[0]['code'] ?? null);
    }

    public function testTheFileNamesNoExpenseCategoryToWhoeverMayNotReadThem(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->import($this->twoVendors());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->companyPath().'/exports/vendors.csv');
        self::assertResponseIsSuccessful();
        $exported = $this->client->getInternalResponse()->getContent();
        self::assertStringNotContainsString('Fournitures', $exported);

        // The empty cell keeps what the vendor has, so the file still comes back whole.
        $this->import($exported, mode: 'upsert');
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame('Fournitures', $this->categoryName($this->vendor('FRN-0001')));
    }

    public function testSomebodyWhoCannotWriteVendorsIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['vendor.read']);

        $this->import($this->twoVendors(), dryRun: true);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function twoVendors(): string
    {
        return self::HEADER
            ."\nFRN-0001,Aciers du Sud,Aciers du Sud SARL,".self::MATRICULE.',compta@aciers.tn,TN5910006035183598478831,BIATTNTT,30,Fournitures,non'
            ."\nFRN-0002,Outillage Pro,,".self::MATRICULE.",,,,,,\n";
    }

    /** A vendor stored before a rule is held to it when a file writes it again, under the column the rule names. */
    public function testAFileUpdatingAVendorStoredBeforeARuleIsRefusedOnTheCellItBreaks(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->import("number,name,email\nFRN-0100,Sotumag,achats@sotumag.tn\n");
        self::assertResponseIsSuccessful();
        $this->em()->getConnection()->executeStatement("UPDATE vendor SET email = 'achats@' WHERE number = 'FRN-0100'");

        $this->import("number,name\nFRN-0100,Sotumag Group\n", mode: 'upsert', dryRun: true);

        self::assertResponseIsSuccessful();
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame([2, 'email', 'invalid_email'], [$rejected[0]['line'] ?? null, $rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null]);
    }

    private function import(string $contents, string $mode = 'create', bool $dryRun = false, string $name = 'vendors.csv'): void
    {
        $this->uploadFile($this->companyPath().'/imports/vendors', $name, $contents, 'file', ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0']);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('buyer@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('buyer@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        // A vendor's bank details are on the step-up list; VendorBankDetailsStepUpTest is about that.
        $this->stepUp('password-1234');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function vendor(string $number): Vendor
    {
        $vendor = $this->em()->getRepository(Vendor::class)->findOneBy(['number' => $number]);
        self::assertInstanceOf(Vendor::class, $vendor);

        return $vendor;
    }

    private function categoryName(Vendor $vendor): ?string
    {
        $id = $vendor->getProfile()->defaultExpenseCategoryId;
        if (null === $id) {
            return null;
        }
        $category = $this->em()->getRepository(ExpenseCategory::class)->find($id);
        self::assertInstanceOf(ExpenseCategory::class, $category);

        return $category->getName();
    }
}
