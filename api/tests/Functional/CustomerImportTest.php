<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\Domain\Model\AuditLog;
use App\CustomFields\Domain\Model\CustomFieldDefinition;
use App\CustomFields\Domain\ValueObject\CustomFieldEntity;
use App\CustomFields\Domain\ValueObject\CustomFieldType;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Model\CustomerGroup;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Infrastructure\Spreadsheet\OpenSpoutWriter;
use App\Tenancy\Domain\Model\Company;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(['committed' => false, 'created' => [2, 3], 'updated' => [], 'unchanged' => [], 'rejected' => [], 'changes' => [], 'notes' => [], 'alreadyImportedAt' => null, 'encoding' => 'utf-8'], $this->json());
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
        self::assertSame(['committed' => true, 'created' => [2, 3], 'updated' => [], 'unchanged' => [], 'rejected' => [], 'changes' => [], 'notes' => [], 'alreadyImportedAt' => null, 'encoding' => 'utf-8'], $this->json());
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

    /**
     * The law asks for the matricule fiscal on the invoice, not on the customer: a business customer a file brings
     * without it is created, and the preview says it is missing, so that a shop's three hundred trade customers are not
     * three hundred refusals (docs/SPEC.md § 7, 2026-10-09 10:40, question 3).
     */
    public function testABusinessCustomerWithoutItsIdentifierIsImportedWithAWarning(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import(self::HEADER."\nCLI-0001,Carthage Conseil,company,,,,,,,\n");

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['created'] ?? null);
        self::assertSame([], $this->json()['rejected'] ?? null);
        self::assertSame(
            [['line' => 2, 'column' => 'matricule_fiscal', 'code' => 'identifier_missing', 'params' => ['identifier' => 'matricule_fiscal']]],
            $this->json()['notes'] ?? null,
        );
        $business = $this->customer('CLI-0001');
        self::assertSame('company', $business->getProfile()->kind->value);
        self::assertSame([], $business->getProfile()->identifiers);
    }

    /** The kind in the words the screen shows, and a day as people here write it. */
    public function testAKindAndADayAreReadAsPeopleWriteThem(): void
    {
        $this->em()->persist(CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'since', 'Client depuis', CustomFieldType::Date, false, [], 2, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import("number,name,kind,matricule_fiscal,custom.since\nCLI-0001,Carthage Conseil,Société,".self::MATRICULE.",15/03/2026\nCLI-0002,Amel Ben Ali,Particulier,,\nCLI-0003,Sfax Outillage,entreprise,".self::MATRICULE.",\n");

        self::assertResponseIsSuccessful();
        self::assertSame([2, 3, 4], $this->json()['created'] ?? null);
        self::assertSame(['company', 'individual', 'company'], [
            $this->customer('CLI-0001')->getProfile()->kind->value,
            $this->customer('CLI-0002')->getProfile()->kind->value,
            $this->customer('CLI-0003')->getProfile()->kind->value,
        ]);
        self::assertSame('2026-03-15', $this->customer('CLI-0001')->getCustomFields()['since'] ?? null);
    }

    /** A row that says nothing of its kind is a business when it carries an identifier, and a person otherwise. */
    public function testABlankKindIsABusinessOnlyWhenTheRowCarriesAnIdentifier(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import(self::HEADER."\nCLI-0001,Carthage Conseil,,".self::MATRICULE.",,,,,,\nCLI-0002,Amel Ben Ali,,,,,,,,\n");

        self::assertResponseIsSuccessful();
        self::assertSame([2, 3], $this->json()['created'] ?? null);
        self::assertSame([], $this->json()['notes'] ?? null, 'a person is asked for no identifier');
        self::assertSame('company', $this->customer('CLI-0001')->getProfile()->kind->value);
        self::assertSame('individual', $this->customer('CLI-0002')->getProfile()->kind->value);
    }

    /** As a spreadsheet writes it: spaced out, in lower case. Kept as the preset writes it. */
    public function testAnIdentifierIsReadWithoutItsSpacesAndInCapitals(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import(self::HEADER."\nCLI-0001,Carthage Conseil,company,\"1234567 a / b / m / 000\",,,,,,\n");

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['created'] ?? null);
        self::assertSame(['matricule_fiscal' => self::MATRICULE], $this->customer('CLI-0001')->getProfile()->identifiers);
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

    /**
     * A file is held to the customer form's own rules for what the domain does not check: an address, a telephone, a
     * website, a country and every length. A country the column cannot hold was a server error on the preview, with no
     * line named.
     */
    public function testAFileIsHeldToTheFormsRulesForContactDetailsAndAddresses(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import(
            "number,name,kind,email,phone,website,billing_country_code,billing_city,shipping_postal_code\n"
            ."CLI-0010,Courriel,individual,pas-un-courriel,,,,,\n"
            ."CLI-0011,Téléphone,individual,,appeler le standard,,,,\n"
            ."CLI-0012,Site,individual,,,pas un site,,,\n"
            ."CLI-0013,Pays,individual,,,,France,,\n"
            .'CLI-0014,Ville,individual,,,,TN,'.str_repeat('x', 121).",\n"
            ."CLI-0015,Livraison,individual,,,,,,123456789012345678901\n"
            ."CLI-0016,Minuscules,individual,compta@exemple.tn,+216 71 000 000,https://exemple.tn,tn,Tunis,\n",
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
            [5, 'billing_country_code', 'invalid_country', []],
            [6, 'billing_city', 'invalid_text', ['max' => 120]],
            [7, 'shipping_postal_code', 'invalid_text', ['max' => 20]],
        ], $rejected);
        self::assertSame([8], $this->arrayAt($this->json(), 'created'), 'a country written in small letters is the same country');
    }

    /** A customer stored before a rule is held to it when a file writes it again, under the column the rule names. */
    public function testAFileUpdatingACustomerStoredBeforeARuleIsRefusedOnTheCellItBreaks(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->import("number,name,kind,email\nCLI-0001,Carthage Conseil,individual,compta@carthage.tn\n");
        self::assertResponseIsSuccessful();
        $this->em()->getConnection()->executeStatement("UPDATE customer SET email = 'compta@' WHERE number = 'CLI-0001'");

        $this->import("number,name\nCLI-0001,Carthage Conseil Group\n", mode: 'upsert', dryRun: true);

        self::assertResponseIsSuccessful();
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame([2, 'email', 'invalid_email'], [$rejected[0]['line'] ?? null, $rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null]);
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
        self::assertSame(['committed' => true, 'created' => [], 'updated' => [2], 'unchanged' => [], 'rejected' => [], 'changes' => [['line' => 2, 'column' => 'name', 'before' => 'Carthage Conseil', 'after' => 'Carthage Conseil Group']], 'notes' => [], 'alreadyImportedAt' => null, 'encoding' => 'utf-8'], $this->json());
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
        self::assertSame(['error' => 'unknown_columns', 'columns' => ['colour'], 'limit' => null, 'encoding' => 'utf-8'], $this->json());
    }

    /** A file in the shop's own words is read through the pairing a person made, which the next file is offered. */
    public function testAFileInItsOwnWordsIsReadThroughAPairingThatIsOfferedAgain(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $pairing = ['Réf. client' => 'number', 'Raison sociale [FR]' => 'name', 'Remarque' => ''];

        $this->import("Réf. client,Raison sociale [FR],Remarque\nCLI-0001,Carthage Conseil,à rappeler\n", pairing: $pairing);

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['created'] ?? null);
        self::assertSame(1, $this->customers());
        $this->getJson($this->companyPath().'/imports/customers');
        self::assertSame(
            [['heading' => 'Réf. client', 'column' => 'number'], ['heading' => 'Raison sociale [FR]', 'column' => 'name'], ['heading' => 'Remarque', 'column' => '']],
            $this->json()['lastPairing'] ?? null,
            'as the file wrote its headings, the one set aside included',
        );

        // A file in the subject's own words pairs nothing, and the shop's own way of writing is still the one offered.
        $this->import("number,name\nCLI-0002,Sousse Bois\n");
        self::assertResponseIsSuccessful();
        $this->getJson($this->companyPath().'/imports/customers');
        self::assertSame(
            [['heading' => 'Réf. client', 'column' => 'number'], ['heading' => 'Raison sociale [FR]', 'column' => 'name'], ['heading' => 'Remarque', 'column' => '']],
            $this->json()['lastPairing'] ?? null,
            'a file needing no pairing leaves the last one offered',
        );
    }

    public function testAPairingNamingNoColumnIsRefusedSayingWhich(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import("Réf. client,name\nCLI-0001,Carthage Conseil\n", pairing: ['Réf. client' => 'reference']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['error' => 'invalid_pairing', 'columns' => ['Réf. client'], 'limit' => null, 'encoding' => 'utf-8'], $this->json());
    }

    public function testAPairingThatIsNotHeadingsToColumnsIsRefused(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        foreach (['not json', '["number"]', '{"Réf": 1}', '"number"'] as $pairing) {
            $this->uploadFile($this->companyPath().'/imports/customers', 'customers.csv', "number,name\nCLI-0001,Carthage Conseil\n", 'file', ['mode' => 'create', 'dryRun' => '1', 'pairing' => $pairing]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $pairing);
            self::assertSame(['error' => 'invalid_request'], $this->json(), $pairing);
        }
    }

    public function testNoImportYetOffersNoPairing(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->getJson($this->companyPath().'/imports/customers');

        self::assertSame([], $this->json()['lastPairing'] ?? null);
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

    /**
     * A discount Excel shows as « 5 % » holds 0.05: read as written, the customer would get a twentieth of one percent.
     * The row is refused, saying how to write it (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 7).
     */
    public function testAnXlsxDiscountShownAsAPercentageIsRefusedSayingHowToWriteIt(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $path = (string) tempnam(sys_get_temp_dir(), 'import-test-');
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['number', 'name', 'kind', 'default_discount_rate']));
        $writer->addRow(new Row([Cell::fromValue('CLI-0009'), Cell::fromValue('Sonia Ben Ali'), Cell::fromValue('individual'), Cell::fromValue(0.05, (new Style())->withFormat('0%'))]));
        $writer->close();
        $contents = (string) file_get_contents($path);
        unlink($path);

        $this->import($contents, dryRun: true, name: 'customers.xlsx');

        self::assertResponseIsSuccessful();
        self::assertSame([['line' => 2, 'column' => 'default_discount_rate', 'code' => 'percent_sign', 'params' => [], 'message' => 'Write 5 for 5 %.']], $this->json()['rejected']);
    }

    /**
     * A file fills in a customer's own terms, credit limit, document language and exemption provision, stored where the
     * customer's settings tab stores them (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 11); a blank cell keeps what is set.
     */
    public function testACustomersOwnSettingsAreFilledInFromTheFile(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import("number,name,kind,payment_terms_days,credit_limit,document_language,exemption_reference\nCLI-0001,Carthage Conseil,individual,45,\"12 500,500\",Anglais,Article 13 du Code de la TVA\n");

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['created']);
        self::assertSame(
            ['document.payment_terms_days' => 45, 'credit.limit' => '12500.500', 'document.language' => 'en', 'document.exemption_reference' => 'Article 13 du Code de la TVA'],
            $this->customerSettings('CLI-0001'),
        );

        $this->import("number,name,payment_terms_days,credit_limit,document_language,exemption_reference\nCLI-0001,Carthage Conseil,60,,,\n", 'upsert');

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['updated']);
        self::assertSame(
            ['document.payment_terms_days' => 60, 'credit.limit' => '12500.500', 'document.language' => 'en', 'document.exemption_reference' => 'Article 13 du Code de la TVA'],
            $this->customerSettings('CLI-0001'),
            'a blank cell keeps what the customer has',
        );
    }

    /**
     * An upsert says per customer what changes, old to new, as the file writes it, settings included; a row that
     * changes nothing is counted apart and writes nothing, not even to the journal (docs/SPEC.md § 7, 2026-10-09
     * 10:40, ruling 5).
     */
    public function testAnUpsertSaysWhatChangesAndWhatDoesNot(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->import($this->twoCustomers());
        self::assertResponseIsSuccessful();

        $this->import("number,name,default_discount_rate,payment_terms_days\nCLI-0001,Carthage Conseil SA,7,45\nCLI-0002,Sonia Ben Ali,,\n", 'upsert', dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['updated']);
        self::assertSame([3], $this->json()['unchanged']);
        self::assertSame([
            ['line' => 2, 'column' => 'name', 'before' => 'Carthage Conseil', 'after' => 'Carthage Conseil SA'],
            ['line' => 2, 'column' => 'default_discount_rate', 'before' => '5.500', 'after' => '7.000'],
            ['line' => 2, 'column' => 'payment_terms_days', 'before' => '', 'after' => '45'],
        ], $this->json()['changes']);

        $journal = $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'customer.%'");
        $this->import("number,name\nCLI-0002,Sonia Ben Ali\n", 'upsert');
        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['unchanged']);
        self::assertSame($journal, $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'customer.%'"), 'a row that changes nothing records nothing');
    }

    /** @return iterable<string, array{string, string, string, array<string, int|string>}> */
    public static function refusedSettings(): iterable
    {
        yield 'terms past the declared maximum' => ['payment_terms_days', '366', 'invalid_payment_terms', ['max' => 365]];
        yield 'terms that are no whole number' => ['payment_terms_days', '30 jours', 'invalid_payment_terms', ['max' => 365]];
        yield 'a credit limit below zero' => ['credit_limit', '-100', 'invalid_credit_limit', ['min' => '0']];
        yield 'a credit limit that is no amount' => ['credit_limit', 'beaucoup', 'invalid_credit_limit', ['min' => '0']];
        yield 'a language documents are not printed in' => ['document_language', 'allemand', 'not_one_of', ['choices' => 'fr, en, ar']];
        yield 'an exemption provision past its length' => ['exemption_reference', str_repeat('a', 201), 'invalid_text', ['max' => 200]];
    }

    /**
     * Each cell is held to its setting's own declaration, so a file allows what the settings tab allows; the row is
     * refused, naming the column and the bound, before the customer is written.
     *
     * @param array<string, int|string> $params
     */
    #[DataProvider('refusedSettings')]
    public function testASettingTheTabWouldRefuseIsRefusedOnItsColumn(string $column, string $cell, string $code, array $params): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import("number,name,kind,$column\nCLI-0001,Carthage Conseil,individual,\"$cell\"\n", dryRun: true);

        self::assertResponseIsSuccessful();
        $rejected = $this->json()['rejected'];
        self::assertIsArray($rejected);
        self::assertSame([[2, $column, $code, $params]], array_map(static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [], $rejected));
    }

    /**
     * A refused row says every fault it holds at once, the form's rules and the cells read alike, in the order of its
     * columns, so a person fixes a file in one pass rather than one fault per try (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 9).
     */
    public function testARefusedRowListsEveryFaultAtOnce(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->import("number,name,kind,email,website,customer_group,default_discount_rate,payment_terms_days,active\nCLI-0001,Carthage Conseil,cooperative,not-an-email,not a website,Nowhere,5%,366,peut-être\n", dryRun: true);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [[2, 'kind', 'not_one_of'], [2, 'email', 'invalid_email'], [2, 'website', 'invalid_website'], [2, 'customer_group', 'unknown_group'], [2, 'default_discount_rate', 'percent_sign'], [2, 'payment_terms_days', 'invalid_payment_terms'], [2, 'active', 'not_yes_or_no']],
            $this->rejectedCells(),
            'in the order of the template’s columns',
        );
        self::assertSame(0, $this->customers());
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

    /** @return list<array{mixed, mixed, mixed}> each rejection's line, column and code, in the report's order */
    private function rejectedCells(): array
    {
        $rejected = $this->json()['rejected'];
        self::assertIsArray($rejected);

        return array_values(array_map(static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null] : [null, null, null], $rejected));
    }

    /**
     * The settings stored on the customer itself, by key: what its settings tab shows as the customer's own.
     *
     * @return array<string, mixed>
     */
    private function customerSettings(string $number): array
    {
        $context = new SettingContext(company: $this->company, customerId: $this->customer($number)->getId());
        $own = [];
        foreach (['document.payment_terms_days', 'credit.limit', 'document.language', 'document.exemption_reference'] as $key) {
            $resolved = static::getContainer()->get(ResolveSettings::class)->one($context, $key);
            if (SettingLevel::Customer === $resolved->source) {
                $own[$key] = $resolved->value;
            }
        }

        return $own;
    }

    private function twoCustomers(): string
    {
        return self::HEADER
            ."\nCLI-0001,Carthage Conseil,company,".self::MATRICULE.',Grossistes,TVA19,"5,5",non,compta@carthage.tn,12'
            ."\nCLI-0002,Sonia Ben Ali,individual,,,,,,,\n";
    }

    /** @param array<string, string>|null $pairing */
    private function import(string $contents, string $mode = 'create', bool $dryRun = false, string $name = 'customers.csv', ?array $pairing = null): void
    {
        $parameters = ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0'];
        if (null !== $pairing) {
            $parameters['pairing'] = json_encode($pairing, \JSON_THROW_ON_ERROR);
        }
        $this->uploadFile($this->companyPath().'/imports/customers', $name, $contents, 'file', $parameters);
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
