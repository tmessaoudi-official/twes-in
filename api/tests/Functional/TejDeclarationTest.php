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
use App\Tenancy\Domain\CompanyProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * A month's withholdings as the file the TEJ platform takes (docs/research/tax-data-tunisia.md § 2.2): read with
 * expense.read, for the company acting only, and answered with a reason instead of a file the platform would refuse.
 */
final class TejDeclarationTest extends ApiTestCase
{
    private Company $company;
    private Vendor $vendor;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Acme SARL', identifiers: ['matricule_fiscal' => '1234567A/B/M/000']));
        $this->vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile(
            'Sotumag',
            identifiers: ['matricule_fiscal' => '7654321B/A/M/000'],
            email: 'achats@sotumag.tn',
            phone: '71000000',
            address: new PostalAddress('4, rue de Marseille', null, '1000', 'Tunis'),
        ), new \DateTimeImmutable());
        $this->em()->persist($this->vendor);
        $this->em()->flush();
        $this->today = new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()));
    }

    public function testTheMonthsWithholdingsDownloadAsTheFileNamedAfterTheMatriculeAndTheMonth(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $id = $this->paidExpense('1000', 'RS7_000001');

        $this->client->request('GET', $this->declarationPath());

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame(\sprintf('attachment; filename=1234567A-%s-0.xml', $this->today->format('Y-m')), $response->headers->get('Content-Disposition'));
        $xml = (string) $response->getContent();
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        foreach ([
            '<Identifiant>1234567A</Identifiant>',
            '<Identifiant>7654321B</Identifiant>',
            "<Ref_certif_chez_declarant>$id</Ref_certif_chez_declarant>",
            '<Operation IdTypeOperation="RS7_000001">',
            // 1000 net at 19 %: 1190 gross, 1.5 % of it 17.850 withheld, 1172.150 handed over.
            '<MontantHT>1000000</MontantHT>', '<MontantTVA>190000</MontantTVA>', '<MontantTTC>1190000</MontantTTC>', '<MontantRS>17850</MontantRS>', '<MontantNetServi>1172150</MontantNetServi>',
            '<DatePayement>'.$this->today->format('d/m/Y').'</DatePayement>',
        ] as $expected) {
            self::assertStringContainsString($expected, $xml);
        }
    }

    public function testWhatThePlatformWouldRefuseIsAnsweredWithAReasonAndThePaymentsThatLackIt(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);

        $this->getJson($this->declarationPath());
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['nothing_to_declare', ['year' => (int) $this->today->format('Y'), 'month' => (int) $this->today->format('n')]], [$this->json()['code'], $this->json()['params']]);

        $coded = $this->paidExpense('1000', 'RS7_000001');
        $uncoded = $this->paidExpense('2000', null);
        $this->getJson($this->declarationPath());
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = $this->json();
        self::assertSame(['incomplete_expenses', ['count' => 1]], [$body['code'], $body['params']]);
        $expenses = $this->arrayAt($body, 'expenses');
        self::assertCount(1, $expenses);
        self::assertIsArray($expenses[0]);
        self::assertSame([$uncoded, ['operation_code_missing']], [$expenses[0]['expenseId'] ?? null, $expenses[0]['problems'] ?? null]);

        $this->postJson($this->expensePath($uncoded).'/withholding-operation', ['withholdingOperationCode' => 'RS7_000002']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->declarationPath());
        self::assertResponseIsSuccessful('once said, the month is declared');
        self::assertSame(2, substr_count((string) $this->client->getResponse()->getContent(), '<Certificat>'), $coded);

        $this->company = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company');
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Acme SARL'));
        $this->em()->flush();
        $this->getJson($this->declarationPath());
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('company_matricule_missing', $this->json()['code']);

        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/withholding-declarations/tej/2026-13');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'no thirteenth month');
    }

    public function testOnlyTheCompanyActingWithExpenseReadAndTheModuleOnGetsItsFile(): void
    {
        // Made before the first request: the test client reboots the kernel, and with it the entity manager.
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['expense.read'], 'reader');
        $this->createUser('vendors@twes.local', 'password-1234', $this->company, ['vendor.read'], 'vendors');
        $this->signedIn(['expense.read', 'expense.write', 'company.read', 'company.settings']);
        $this->paidExpense('1000', 'RS7_000001');

        $globex = $this->createCompany('Globex');
        $this->client->request('GET', $this->declarationPath($globex));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company’s month');

        $this->sendJson('PUT', $this->companyPath().'/modules/expenses', ['enabled' => false]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->declarationPath());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the expenses module is off');
        $this->sendJson('PUT', $this->companyPath().'/modules/expenses', ['enabled' => true]);

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->client->request('GET', $this->declarationPath());
        self::assertResponseIsSuccessful('reading the expenses is enough');

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('vendors@twes.local', 'password-1234');
        $this->client->request('GET', $this->declarationPath());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the vendor book’s permission is not the expenses’');
    }

    /** A recorded expense of today, paid today at 1.5 % under the code given; its id. */
    private function paidExpense(string $net, ?string $code): string
    {
        $this->postJson($this->companyPath().'/expense-categories', ['name' => 'Achats '.$net, 'parentId' => null, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $category = $this->stringAt($this->json(), 'id');
        $tax = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->company->getId()->toRfc4122(), 'TVA19']);
        self::assertIsString($tax);
        $this->postJson($this->expensePath(), ['date' => $this->today->format('Y-m-d'), 'reference' => 'F-'.$net, 'description' => 'Achat '.$net, 'vendorId' => $this->vendor->getId()->toRfc4122(), 'categoryId' => $category, 'amountNet' => $net, 'taxComponentId' => $tax, 'notes' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->expensePath($id).'/record', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->expensePath($id).'/pay', ['paymentMethod' => 'transfer', 'paidOn' => $this->today->format('Y-m-d'), 'withholdingRate' => '1.5'] + (null === $code ? [] : ['withholdingOperationCode' => $code]));
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('buyer@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('buyer@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(?Company $company = null): string
    {
        return '/api/companies/'.($company ?? $this->company)->getId()->toRfc4122();
    }

    private function expensePath(?string $id = null): string
    {
        return $this->companyPath().'/expenses'.(null === $id ? '' : '/'.$id);
    }

    private function declarationPath(?Company $company = null): string
    {
        return $this->companyPath($company).'/withholding-declarations/tej/'.$this->today->format('Y-m');
    }
}
