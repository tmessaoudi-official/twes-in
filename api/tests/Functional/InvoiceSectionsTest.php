<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * An invoice's sections: a line carrying a title opens one, which runs to the next titled line, and each section's
 * subtotal is what its lines' nets add up to, excluding tax, worked out by the API. The title is the line's, so it is
 * saved, revised, issued, copied and credited with it, printed on the PDF, and left out of Factur-X, which has none.
 */
final class InvoiceSectionsTest extends ApiTestCase
{
    private const array WRITER = ['company.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit'];

    private Company $company;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->em()->persist($this->company);
        $this->em()->flush();
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Atelier Durand SARL', identifiers: ['siren' => '732829320', 'siret' => '73282932000074', 'vat_number' => 'FR44732829320'], addressLine1: '12 rue des Forges', postalCode: '69007', city: 'Lyon'));
        $this->em()->flush();
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($this->company), 'document.late_payment_rate', SettingLevel::Company, 'trois fois le taux d’intérêt légal', null);
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('FR', 'standard');
        self::assertNotNull($regime);
        $this->customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Garage Martin', identifiers: ['siren' => '542065479'], billingAddress: new PostalAddress('3 avenue Foch', null, '75016', 'Paris', 'FR')), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($this->customer);
        $this->em()->flush();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, self::WRITER, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testATitledLineOpensASectionAndTheReadSaysEachSubtotal(): void
    {
        $id = $this->draft($this->job());

        $invoice = $this->invoice($id);
        self::assertSame([null, 'Démontage', null, 'Pièces'], array_column($this->arrayAt($invoice, 'lines'), 'section'), 'a title is trimmed and kept on its line');
        self::assertSame([
            ['title' => 'Démontage', 'firstLine' => 1, 'lineCount' => 2, 'subtotal' => '25.50'],
            ['title' => 'Pièces', 'firstLine' => 3, 'lineCount' => 1, 'subtotal' => '100.00'],
        ], $invoice['sections'], 'the lines before the first title are in none');
        self::assertSame('165.50', $invoice['subtotalNet'], 'a title changes no figure');
    }

    public function testABlankTitleIsNoneAndATooLongOneIsRefused(): void
    {
        $id = $this->draft([$this->freeLine('Pose', '10', '   ')]);
        self::assertSame([null], array_column($this->arrayAt($this->invoice($id), 'lines'), 'section'));
        self::assertSame([], $this->invoice($id)['sections']);

        $this->postJson($this->invoicePath(), $this->body([$this->freeLine('Pose', '10'), $this->freeLine('Dépose', '5', str_repeat('é', 121))]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('lines[1].section', $this->stringAt($this->json(), 'detail'));
    }

    public function testATitleAloneIsAChangeOfTheLinesAndIsAudited(): void
    {
        $id = $this->draft([$this->freeLine('Pose', '10'), $this->freeLine('Dépose', '5')]);

        $this->sendJson('PUT', $this->invoicePath($id), $this->body([$this->freeLine('Pose', '10', 'Atelier'), $this->freeLine('Dépose', '5')]));

        self::assertResponseIsSuccessful();
        self::assertSame([['title' => 'Atelier', 'firstLine' => 0, 'lineCount' => 2, 'subtotal' => '15.00']], $this->json()['sections']);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['lines']], json_decode($changes, true));
    }

    public function testThePreviewSaysEachSectionsSubtotalAndKeepsNothing(): void
    {
        $this->postJson($this->invoicePath().'/preview', $this->body($this->job()));

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['title' => 'Démontage', 'firstLine' => 1, 'lineCount' => 2, 'subtotal' => '25.50'],
            ['title' => 'Pièces', 'firstLine' => 3, 'lineCount' => 1, 'subtotal' => '100.00'],
        ], $this->json()['sections']);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'), 'a preview creates no invoice');
    }

    public function testAnIssuedInvoicePrintsItsSectionsAndItsFacturXHasNone(): void
    {
        $id = $this->draft($this->job());
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseIsSuccessful();
        self::assertSame(['25.50', '100.00'], array_column($this->arrayAt($this->invoice($id), 'sections'), 'subtotal'), 'an issued invoice adds its frozen nets');

        $this->client->request('GET', $this->invoicePath($id).'/pdf/current');
        $pdf = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Démontage', $pdf);
        self::assertStringContainsString('Sous-total Démontage', $pdf);
        self::assertStringContainsString('Sous-total Pièces', $pdf);
        self::assertStringContainsString('25,50', $pdf);

        $this->client->request('GET', $this->invoicePath($id).'/factur-x.xml');
        self::assertResponseIsSuccessful();
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Écrou M8', $xml, 'the lines are all there');
        self::assertStringNotContainsString('Démontage', $xml, 'Factur-X has no sections: the lines stay flat');
        self::assertStringNotContainsString('Pièces', $xml);
    }

    /** Each recurring draft is made as a duplicate is (`Invoice::duplicateOf`), so it keeps them too. */
    public function testACreditNoteAndADuplicateKeepTheSections(): void
    {
        $id = $this->draft($this->job());
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseIsSuccessful();
        $sections = $this->invoice($id)['sections'];

        $this->postJson($this->invoicePath($id).'/credit-notes', ['creditNoteReason' => 'Travaux repris']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([
            ['title' => 'Démontage', 'firstLine' => 1, 'lineCount' => 2, 'subtotal' => '-25.50'],
            ['title' => 'Pièces', 'firstLine' => 3, 'lineCount' => 1, 'subtotal' => '-100.00'],
        ], $this->invoice($this->stringAt($this->json(), 'id'))['sections'], 'a correction concerns the same lines, which it takes back');

        $this->postJson($this->invoicePath($id).'/duplicate', null);
        self::assertResponseIsSuccessful();
        self::assertSame($sections, $this->invoice($this->stringAt($this->json(), 'id'))['sections'], 'a copy is the same sale');
    }

    public function testAnotherCompanysInvoiceIsNotGivenATitle(): void
    {
        $id = $this->draft([$this->freeLine('Pose', '10')]);
        $globex = $this->createCompany('Globex');
        $this->createUser('other@twes.local', 'password-1234', $globex, self::WRITER, 'member');
        $this->login('other@twes.local', 'password-1234');

        $this->sendJson('PUT', '/api/companies/'.$globex->getId()->toRfc4122().'/invoices/'.$id, $this->body([$this->freeLine('Pose', '10', 'Atelier')]));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return list<array<string, mixed>> a job in parts: a line in no section, then two sections */
    private function job(): array
    {
        return [
            $this->freeLine('Déplacement', '40'),
            $this->freeLine('Dépose du carter', '10.50', '  Démontage '),
            $this->freeLine('Nettoyage', '15'),
            $this->freeLine('Écrou M8', '100', 'Pièces'),
        ];
    }

    /** @return array<string, mixed> */
    private function freeLine(string $description, string $price, ?string $section = null): array
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA20', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($tax);

        return ['description' => $description, 'quantity' => '1', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => $price, 'taxComponentIds' => [$tax->getId()->toRfc4122()], 'section' => $section];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function body(array $lines): array
    {
        return ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'operationCategory' => 'services', 'lines' => $lines];
    }

    /** @param list<array<string, mixed>> $lines */
    private function draft(array $lines): string
    {
        $this->postJson($this->invoicePath(), $this->body($lines));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @return array<string, mixed> */
    private function invoice(string $id): array
    {
        $this->getJson($this->invoicePath($id));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function invoicePath(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
