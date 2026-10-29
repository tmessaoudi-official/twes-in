<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\RunDataChecks;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Infrastructure\Scheduler\MarkKeptCustomerContactsEveryNight;
use App\Module\Vendors\Infrastructure\Scheduler\MarkKeptVendorContactsEveryNight;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\ShippedLanguages;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The data check (docs/SPEC.md § 8 row 182): every invariant the contexts declare, run over the whole database, each
 * finding named by its check and company. Each case breaks one invariant the way only a direct write could, which no
 * use case allows, and the check must find it; the operators are told the check, the company and a count, never what
 * the document says, since they keep no standing access to a company's data.
 */
final class DataCheckTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customerOf($this->company, 'Carthage Conseil');
        $this->createUser('operator@twes.local', 'password-1234', operator: true);
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue'], 'member');
        $this->login('sales@twes.local', 'password-1234');
    }

    public function testADatabaseAsTheUseCasesLeaveItHasNoFindingAndTellsNobody(): void
    {
        $this->issue($this->draft('100'));
        $this->issue($this->draft('200'));
        // What the demo data has none of: a document discount, under a percentage tax.
        $this->issue($this->draft('333.333', discount: '12.5', taxed: true));

        self::assertSame([], $this->findings());
        $run = $this->check();
        self::assertSame(0, $run->getStatusCode(), $run->getDisplay());
        self::assertSame([], $this->told());
    }

    public function testATotalChangedAfterIssueIsFoundAndTheOperatorsAreToldOnlyTheCheckTheCompanyAndACount(): void
    {
        $this->issue($this->draft('100'));
        $id = $this->draft('200');
        $this->issue($id);
        $this->connection()->executeStatement('UPDATE invoice SET total_gross = total_gross + 1 WHERE id = ?', [$id]);

        self::assertContains('invoices.chain', $this->checksFound());
        $arithmetic = array_values(array_filter($this->findings(), static fn (DataFinding $finding): bool => 'invoices.arithmetic' === $finding->check));
        self::assertCount(1, $arithmetic);
        // The gross no longer adds up, and neither does what is due from it: each identity is checked on its own.
        self::assertStringContainsString('gross', $arithmetic[0]->detail);
        self::assertStringContainsString('due', $arithmetic[0]->detail);

        $run = $this->check();
        self::assertSame(1, $run->getStatusCode(), $run->getDisplay());
        $told = $this->told();
        self::assertNotSame([], $told);
        foreach ($told as $payload) {
            self::assertSame(['check', 'label_key', 'company', 'count'], array_keys($payload));
            self::assertSame('Acme', $payload['company']);
            self::assertStringNotContainsString('FAC-', (string) json_encode($payload), 'no document number reaches an operator');
        }

        // The mail names each check by its label in the operator's language, never by its code.
        self::assertGreaterThan(0, $this->deliverQueued());
        $bodies = implode("\n", array_map(static fn (RawMessage $mail): string => $mail instanceof Email ? (string) $mail->getHtmlBody() : '', self::getMailerMessages()));
        self::assertStringContainsString('Chaîne des factures', $bodies);
        self::assertStringNotContainsString('data_checks.', $bodies);
        self::assertStringNotContainsString('invoices.chain', $bodies);
        self::assertStringNotContainsString('FAC-', $bodies);
    }

    public function testTheNewestDocumentTakenOutIsFound(): void
    {
        $this->issue($this->draft('100'));
        $this->issue($this->draft('200'));
        $newest = $this->draft('300');
        $this->issue($newest);
        // What the chain alone cannot show: a shorter chain whose every link holds.
        $this->connection()->executeStatement('DELETE FROM invoice_line_tax WHERE line_id IN (SELECT id FROM invoice_line WHERE invoice_id = ?)', [$newest]);
        $this->connection()->executeStatement('DELETE FROM invoice_line WHERE invoice_id = ?', [$newest]);
        $this->connection()->executeStatement('DELETE FROM invoice WHERE id = ?', [$newest]);

        self::assertContains('invoices.chain', $this->checksFound());
    }

    public function testANumberSkippedInASeriesIsFound(): void
    {
        $this->issue($this->draft('100'));
        $second = $this->draft('200');
        $this->issue($second);
        $this->connection()->executeStatement("UPDATE invoice SET number = regexp_replace(number, '[0-9]+$', '00009') WHERE id = ?", [$second]);

        self::assertContains('invoices.numbering', $this->checksFound());
    }

    public function testNumbersStartingAgainAtOneWhereAMonthBeginsAreNoGapWhateverTheResetSaysToday(): void
    {
        $ids = [$this->draft('100'), $this->draft('200'), $this->draft('300'), $this->draft('400')];
        foreach ($ids as $id) {
            $this->issue($id);
        }
        // Numbered yearly for two months, then monthly: a series' reset may be revised once it has numbered documents.
        $this->renumber($ids, [['2026-01-10', 'FAC-2026-01-00001'], ['2026-01-20', 'FAC-2026-01-00002'], ['2026-02-05', 'FAC-2026-02-00003'], ['2026-03-01', 'FAC-2026-03-00001']]);
        $this->connection()->executeStatement("UPDATE numbering_series SET reset_period = 'monthly' WHERE company_id = ? AND document_type = 'invoice'", [$this->company->getId()->toRfc4122()]);

        self::assertNotContains('invoices.numbering', $this->checksFound());

        // Starting again anywhere but where a month begins is a gap.
        $this->renumber([$ids[3]], [['2026-02-20', 'FAC-2026-02-00001']]);

        self::assertContains('invoices.numbering', $this->checksFound());
    }

    public function testAReferenceIntoAnotherCompanyIsFound(): void
    {
        $id = $this->draft('100');
        $this->issue($id);
        $elsewhere = $this->createCompany('Elsewhere');
        static::getContainer()->get(ProvisionCompany::class)->handle($elsewhere);
        $this->connection()->executeStatement('UPDATE invoice SET customer_id = ? WHERE id = ?', [$this->customerOf($elsewhere, 'Globex'), $id]);

        self::assertContains('references.company', $this->checksFound());
    }

    /**
     * Row 208: an address or a phone kept from before today's rules stays until someone changes it, and the night's
     * check lists each one so it is corrected; the finding names the record and the field, never the value.
     */
    public function testAnAddressOrAPhoneTodaysRulesRefuseIsFoundByItsRecordAndFieldNeverItsValue(): void
    {
        $db = $this->connection();
        $db->executeStatement("UPDATE customer SET email = 'compta@carthage', phone = '+216 11 111 111' WHERE id = ?", [$this->customerId]);
        $db->executeStatement("INSERT INTO contact (id, company_id, customer_id, email, phone, is_primary, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, NULL, '12', false, now(), now())", [$this->company->getId()->toRfc4122(), $this->customerId]);
        $db->executeStatement("INSERT INTO vendor (id, company_id, number, name, email, phone, is_active, created_at, updated_at) VALUES (gen_random_uuid(), ?, 'FRN-1', 'Atlas', 'achats@', NULL, true, now(), now())", [$this->company->getId()->toRfc4122()]);
        $db->executeStatement("UPDATE company SET email = 'contact@acme', phone = '+216 71 900 868' WHERE id = ?", [$this->company->getId()->toRfc4122()]);
        $db->executeStatement("UPDATE establishment SET phone = 'joindre' WHERE company_id = ?", [$this->company->getId()->toRfc4122()]);

        $found = array_values(array_filter($this->findings(), static fn (DataFinding $finding): bool => str_ends_with($finding->check, '.contact_rules')));
        $by = [];
        foreach ($found as $finding) {
            self::assertSame($this->company->getId()->toRfc4122(), $finding->companyId?->toRfc4122());
            // « <record> [<number>]: <field> », read as its record and field.
            $by[$finding->check][] = rtrim(explode(' ', $finding->detail)[0], ':').' '.substr($finding->detail, (int) strrpos($finding->detail, ': ') + 2);
            foreach (['carthage', '111', 'achats', 'acme', 'joindre'] as $value) {
                self::assertStringNotContainsString($value, $finding->detail, 'a finding never says the value');
            }
        }
        ksort($by);
        $by = array_map(static function (array $fields): array {
            sort($fields);

            return $fields;
        }, $by);
        // The company's phone is valid, its address is not; the customer's phone is one the plan reads to check, kept.
        self::assertSame([
            'customers.contact_rules' => ['contact phone', 'customer email'],
            'tenancy.contact_rules' => ['company email', 'establishment phone'],
            'vendors.contact_rules' => ['vendor email'],
        ], $by);
    }

    /**
     * Row 208's marks: the night leaves « À vérifier » on each customer and vendor keeping such a value, a line for each
     * in the company's language naming the value, once; a record already marked keeps its own note.
     */
    public function testTheNightMarksEachCustomerAndVendorKeepingOneOnceInItsCompanysLanguage(): void
    {
        $db = $this->connection();
        $company = $this->company->getId()->toRfc4122();
        $db->executeStatement("UPDATE customer SET email = 'compta@carthage' WHERE id = ?", [$this->customerId]);
        $db->executeStatement("INSERT INTO contact (id, company_id, customer_id, first_name, phone, is_primary, created_at, updated_at) VALUES (gen_random_uuid(), ?, ?, 'Leila', '12', false, now(), now())", [$company, $this->customerId]);
        $vendor = $db->fetchOne("INSERT INTO vendor (id, company_id, number, name, email, is_active, created_at, updated_at) VALUES (gen_random_uuid(), ?, 'FRN-1', 'Atlas', 'achats@', true, now(), now()) RETURNING id", [$company]);
        $seen = $this->customerOf($this->company, 'Déjà vu');
        $db->executeStatement("UPDATE customer SET email = 'x@', to_check = true, check_note = 'Vu au téléphone' WHERE id = ?", [$seen]);
        $night = function (): void {
            $this->em()->clear();
            static::getContainer()->get(MarkKeptCustomerContactsEveryNight::class)();
            static::getContainer()->get(MarkKeptVendorContactsEveryNight::class)();
        };

        $night();

        $customer = $db->fetchAssociative('SELECT to_check, check_note FROM customer WHERE id = ?', [$this->customerId]);
        self::assertIsArray($customer);
        self::assertTrue($customer['to_check']);
        self::assertIsString($customer['check_note']);
        [$email, $phone] = explode("\n", $customer['check_note']);
        self::assertSame("Adresse e-mail «\u{a0}compta@carthage\u{a0}» ne suit plus la règle\u{a0}: ce n’est pas une adresse e-mail. À corriger.", $email);
        self::assertStringStartsWith("Téléphone du contact Leila «\u{a0}12\u{a0}» ne suit plus la règle\u{a0}:", $phone);
        self::assertSame([true, "Adresse e-mail «\u{a0}achats@\u{a0}» ne suit plus la règle\u{a0}: ce n’est pas une adresse e-mail. À corriger."], array_values((array) $db->fetchAssociative('SELECT to_check, check_note FROM vendor WHERE id = ?', [$vendor])));
        self::assertSame([true, 'Vu au téléphone'], array_values((array) $db->fetchAssociative('SELECT to_check, check_note FROM customer WHERE id = ?', [$seen])), 'a mark already there is left as it is');
        $journal = "SELECT count(*) FROM audit_log WHERE action IN ('customer.revised', 'vendor.revised') AND actor_user_id IS NULL AND company_id = ?";
        self::assertSame(2, $db->fetchOne($journal, [$company]), 'each mark is in the journal, made by no one');

        $night();

        self::assertSame(2, $db->fetchOne($journal, [$company]), 'the next night writes nothing again');
        self::assertSame($customer['check_note'], $db->fetchOne('SELECT check_note FROM customer WHERE id = ?', [$this->customerId]));
    }

    public function testEveryCheckDeclaredIsNamedInEveryLanguageOfTheMailsAndTheScreens(): void
    {
        $checks = static::getContainer()->get(RunDataChecks::class)->checks();
        self::assertGreaterThanOrEqual(4, \count($checks));
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $screens = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/web/public/i18n/'.$language.'.json'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($screens);
            foreach ($checks as $check) {
                $key = RunDataChecks::labelKey($check);
                self::assertTrue($translator->getCatalogue($language)->defines($key, 'emails'), "$key in emails.$language.yaml");
                [$section, $name] = explode('.', $key, 2);
                self::assertIsArray($screens[$section] ?? null);
                self::assertIsString($screens[$section][$name] ?? null, "$key in web/public/i18n/$language.json");
            }
        }
    }

    /** @return list<DataFinding> */
    private function findings(): array
    {
        $this->em()->clear();

        return static::getContainer()->get(RunDataChecks::class)->findings();
    }

    /** @return list<string> */
    private function checksFound(): array
    {
        return array_values(array_unique(array_map(static fn (DataFinding $finding): string => $finding->check, $this->findings())));
    }

    private function check(): ApplicationTester
    {
        $this->em()->clear();
        $application = new Application(self::$kernel ?? self::fail('No kernel.'));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'app:data:check']);

        return $tester;
    }

    /** @return list<array<mixed>> */
    private function told(): array
    {
        $told = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT payload FROM inbox_item WHERE type = 'data.check_failed'") as $payload) {
            self::assertIsString($payload);
            $decoded = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $told[] = $decoded;
        }

        return $told;
    }

    private function customerOf(Company $company, string $name): string
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($company, 'CLI-'.bin2hex(random_bytes(3)), self::aTunisianBusiness($name), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer->getId()->toRfc4122();
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function issue(string $id): void
    {
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param list<string>                $ids
     * @param list<array{string, string}> $days the issue day and the number each document is given
     */
    private function renumber(array $ids, array $days): void
    {
        foreach ($ids as $n => $id) {
            $this->connection()->executeStatement('UPDATE invoice SET issue_date = ?, number = ? WHERE id = ?', [$days[$n][0], $days[$n][1], $id]);
        }
    }

    private function draft(string $price, ?string $discount = null, bool $taxed = false): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $taxes = [];
        if ($taxed) {
            $vat = $this->connection()->fetchOne("SELECT id FROM tax_component WHERE company_id = ? AND code = 'TVA19'", [$this->company->getId()->toRfc4122()]);
            self::assertIsString($vat);
            $taxes = [$vat];
        }
        $this->postJson($this->path(), [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => $discount,
            'documentTaxComponentIds' => null,
            'lines' => [['description' => 'Conseil', 'quantity' => '2', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => $price, 'taxComponentIds' => $taxes]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
