<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseDetails;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Tenancy\Domain\Company;
use App\Tests\Unit\Files\Application\AttachmentsTest;
use Symfony\Component\HttpFoundation\Response;

final class ExpensesTest extends ApiTestCase
{
    private Company $company;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag', paymentTermsDays: 30), new \DateTimeImmutable());
        $this->em()->persist($this->vendor);
        $this->em()->flush();
    }

    public function testTheOptionsSayWhatAnExpenseAsksFor(): void
    {
        $this->signedIn(['expense.read']);

        $this->getJson($this->companyPath().'/expense-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame(['TND', 3], [$options['currency'], $options['currencyScale']]);
        self::assertSame(['transfer', 'cash', 'check', 'card', 'other'], $options['paymentMethods']);
        $codes = array_column($this->arrayAt($options, 'taxes'), 'code');
        self::assertContains('TVA19', $codes);
        self::assertContains('FODEC', $codes);
        self::assertNotContains('TIMBRE', $codes, 'only a rate on the net taxes an expense');
        self::assertNotContains('RS1', $codes);
        self::assertArrayNotHasKey('vendors', $options, 'the book is asked for a few at a time, not handed over');
    }

    /**
     * The vendors the expense form offers while a person types (docs/SPEC.md § 7, 2026-09-17, ruling 3), under the
     * EXPENSE's permission: writing an expense is enough, reading the vendor book is not asked for. A vendor already
     * named is answered by id whether or not it is still active — an expense recorded last year still names who it
     * was paid to.
     */
    public function testTheVendorPickerAnswersTheFewTheExpenseFormNeeds(): void
    {
        $retired = Vendor::create($this->company, 'FRN-9999', new VendorProfile('Ancienne Papeterie'), new \DateTimeImmutable());
        $retired->revise('FRN-9999', new VendorProfile('Ancienne Papeterie'), false, new \DateTimeImmutable());
        $this->em()->persist($retired);
        $this->em()->flush();
        $this->signedIn(['expense.read']);

        $this->getJson($this->companyPath().'/expense-options/vendors?q=sotumag');

        self::assertResponseIsSuccessful();
        $picks = $this->jsonList();
        self::assertCount(1, $picks);
        self::assertSame(['id', 'number', 'name', 'paymentTermsDays', 'defaultExpenseCategoryId'], array_keys($picks[0]));
        self::assertSame(['FRN-0001', 'Sotumag', 30, null], [$picks[0]['number'], $picks[0]['name'], $picks[0]['paymentTermsDays'], $picks[0]['defaultExpenseCategoryId']]);

        $this->getJson($this->companyPath().'/expense-options/vendors');
        self::assertResponseIsSuccessful();
        self::assertSame(['FRN-0001'], array_column($this->jsonList(), 'number'), 'a retired vendor is not offered');

        $this->getJson($this->companyPath().'/expense-options/vendors?ids[]='.$retired->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSame(['FRN-9999'], array_column($this->jsonList(), 'number'), 'but is answered where an expense names it');
    }

    /** The placement's whole point, and its mirror: the vendor book's own permission does not open this. */
    public function testTheVendorPermissionDoesNotAnswerTheExpenseFormsPicker(): void
    {
        $this->signedIn(['vendor.read']);

        $this->getJson($this->companyPath().'/expense-options/vendors');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCategoriesFormATreeNamedOnceAndAreDeactivatedNeverDeleted(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);

        $vehicles = $this->category('Véhicules');
        $fuel = $this->category('Carburant', $vehicles);
        $this->postJson($this->companyPath().'/expense-categories', ['name' => 'Carburant', 'parentId' => null, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->sendJson('PUT', $this->companyPath().'/expense-categories/'.$vehicles, ['name' => 'Véhicules', 'parentId' => $fuel, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('parentId', (string) $this->client->getResponse()->getContent());

        $this->sendJson('PUT', $this->companyPath().'/expense-categories/'.$fuel, ['name' => 'Carburant', 'parentId' => $vehicles, 'isActive' => false]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->companyPath().'/expense-categories');
        self::assertSame([['Carburant', $vehicles, false], ['Véhicules', null, true]], array_map(static fn (array $row) => [$row['name'], $row['parentId'], $row['isActive']], $this->jsonList()));
        $this->getJson($this->companyPath().'/expense-options');
        self::assertSame(['Véhicules'], array_column($this->arrayAt($this->json(), 'categories'), 'name'), 'an inactive category is not offered');
        $this->sendJson('DELETE', $this->companyPath().'/expense-categories/'.$fuel);
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testAWriterDraftsAnExpenseWhoseFiguresComeFromTheNetAndTheRate(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $fuel = $this->category('Carburant');

        $this->postJson($this->path(), $this->expense(['categoryId' => $fuel]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame(
            ['draft', '2026-09-10', 'F-2026-118', 'Sotumag', 'Carburant', '100.005', '19.000', '19.001', '119.006', 'TND', '2026-10-10', null, null, 0],
            [$created['status'], $created['date'], $created['reference'], $created['vendorName'], $created['categoryName'], $created['amountNet'], $created['taxRate'], $created['taxAmount'], $created['amountGross'], $created['currency'], $created['dueDate'], $created['paymentMethod'], $created['paidOn'], $created['attachmentCount']],
        );
        $this->getJson($this->path($this->stringAt($created, 'id')));
        self::assertResponseIsSuccessful();
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'expense.created'"));
    }

    public function testWhatTheShapeOrTheCompanyRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $stamp = $this->taxId('TIMBRE');

        foreach ([
            'description' => ['description' => ''],
            'amountNet' => ['amountNet' => '0'],
            'amountNet ' => ['amountNet' => '1.2345'],
            'date' => ['date' => '15/09/2026'],
            'reference' => ['reference' => str_repeat('x', 65)],
            'taxComponentId' => ['taxComponentId' => $stamp],
            'categoryId' => ['categoryId' => '01920000-0000-7000-8000-000000000000'],
            'vendorId' => ['vendorId' => '01920000-0000-7000-8000-000000000000'],
        ] as $field => $change) {
            $this->postJson($this->path(), $this->expense($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString(trim($field), (string) $this->client->getResponse()->getContent(), $field);
        }
        $this->getJson($this->path());
        self::assertCount(0, $this->jsonList());
    }

    public function testRecordingNeedsACategoryAndFreezesTheExpenseWhichIsThenPaidOnADayThatHappened(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $today = new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()));
        $this->postJson($this->path(), $this->expense(['date' => $today->modify('-10 days')->format('Y-m-d')]));
        $id = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path($id).'/record', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('categoryId', (string) $this->client->getResponse()->getContent());
        $this->postJson($this->path($id).'/pay', ['paymentMethod' => 'cash', 'paidOn' => $today->format('Y-m-d')]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft is not paid');

        $this->sendJson('PUT', $this->path($id), $this->expense(['date' => $today->modify('-10 days')->format('Y-m-d'), 'categoryId' => $this->category('Carburant'), 'amountNet' => '200']));
        self::assertResponseIsSuccessful();
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'expense.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['amountNet', 'categoryId']], json_decode($changes, true));
        $this->postJson($this->path($id).'/record', null);
        self::assertResponseIsSuccessful();
        self::assertSame('recorded', $this->json()['status']);

        $this->sendJson('PUT', $this->path($id), $this->expense(['categoryId' => null]));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        foreach ([$today->modify('-11 days'), $today->modify('+1 day')] as $impossible) {
            $this->postJson($this->path($id).'/pay', ['paymentMethod' => 'cash', 'paidOn' => $impossible->format('Y-m-d')]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $impossible->format('Y-m-d'));
            self::assertStringContainsString('paidOn', (string) $this->client->getResponse()->getContent());
        }
        $this->postJson($this->path($id).'/pay', ['paymentMethod' => 'bitcoin', 'paidOn' => $today->format('Y-m-d')]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson($this->path($id).'/pay', ['paymentMethod' => 'transfer', 'paidOn' => $today->modify('-2 days')->format('Y-m-d')]);
        self::assertResponseIsSuccessful();
        self::assertSame(['paid', 'transfer', $today->modify('-2 days')->format('Y-m-d')], [$this->json()['status'], $this->json()['paymentMethod'], $this->json()['paidOn']]);
        $this->sendJson('DELETE', $this->path($id));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'only a draft is deleted');
        self::assertSame(['expense.created', 'expense.revised', 'expense.recorded', 'expense.paid'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'expense' ORDER BY at, id"));
    }

    public function testAPageOfTheListCostsTheSameStatementsWhateverTheRowsItHolds(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        foreach (range(1, 6) as $n) {
            $this->postJson($this->path(), $this->expense(['reference' => 'F-2026-10'.$n]));
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            if (0 === $n % 2) {
                $this->uploadFile($this->path($this->stringAt($this->json(), 'id')).'/attachments', 'recu.pdf', AttachmentsTest::PDF);
                self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            }
        }
        $this->em()->clear();

        $statements = [1 => $this->statementsForAPageOf($this->path(), 1), 6 => $this->statementsForAPageOf($this->path(), 6)];

        self::assertSame($statements[1], $statements[6], 'six rows cost what one does (audit PF-07)');
        // Measured 11 on 2026-09-25 (16 for six rows before), the session and the company's checks included.
        self::assertLessThanOrEqual(11, $statements[6]);
        $counts = array_column($this->jsonList(), 'attachmentCount', 'reference');
        ksort($counts);
        self::assertSame(['F-2026-101' => 0, 'F-2026-102' => 1, 'F-2026-103' => 0, 'F-2026-104' => 1, 'F-2026-105' => 0, 'F-2026-106' => 1], $counts, 'each row still counts its own');
    }

    public function testAReceiptIsAttachedByItsBytesListedDownloadedAndDetachedWhileTheExpenseIsADraft(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $this->postJson($this->path(), $this->expense(['categoryId' => $this->category('Carburant')]));
        $id = $this->stringAt($this->json(), 'id');

        $this->uploadFile($this->path($id).'/attachments', 'C:\\fakepath\\recu.pdf', AttachmentsTest::PDF);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $receipt = $this->json();
        $receiptId = $this->stringAt($receipt, 'id');
        self::assertSame(['recu.pdf', 'application/pdf', \strlen(AttachmentsTest::PDF)], [$receipt['name'], $receipt['mime'], $receipt['size']]);

        foreach (['text named like a PDF' => ['facture.pdf', 'Bonjour'], 'nothing' => ['vide.pdf', '']] as $case => [$name, $contents]) {
            $this->uploadFile($this->path($id).'/attachments', $name, $contents);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            self::assertStringContainsString('file', (string) $this->client->getResponse()->getContent(), $case);
        }
        $this->uploadFile($this->path($id).'/attachments', 'recu.pdf', AttachmentsTest::PDF, 'document');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'no part named file');

        $this->getJson($this->path($id).'/attachments');
        self::assertResponseIsSuccessful();
        self::assertSame([$receiptId], array_column($this->jsonList(), 'id'));
        $this->getJson($this->path($id));
        self::assertSame(1, $this->json()['attachmentCount']);

        $this->client->request('GET', $this->path($id).'/attachments/'.$receiptId.'/content');
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame(AttachmentsTest::PDF, $response->getContent());
        self::assertSame(['application/pdf', 'nosniff'], [$response->headers->get('content-type'), $response->headers->get('x-content-type-options')]);
        self::assertStringContainsString('recu.pdf', (string) $response->headers->get('content-disposition'));

        $this->uploadFile($this->path($id).'/attachments', 'photo.png', (string) base64_decode(AttachmentsTest::PNG, true));
        $photo = $this->stringAt($this->json(), 'id');
        $this->sendJson('DELETE', $this->path($id).'/attachments/'.$receiptId);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(2, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM file'), 'a detached file keeps its bytes');

        $this->postJson($this->path($id).'/record', null);
        $this->uploadFile($this->path($id).'/attachments', 'avoir.pdf', AttachmentsTest::PDF);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a receipt arriving late is still attached');
        $this->sendJson('DELETE', $this->path($id).'/attachments/'.$photo);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'what a recorded expense rests on stays');
    }

    public function testADraftIsDeletedWithItsAttachments(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $this->postJson($this->path(), $this->expense());
        $id = $this->stringAt($this->json(), 'id');
        $this->uploadFile($this->path($id).'/attachments', 'recu.pdf', AttachmentsTest::PDF);

        $this->sendJson('DELETE', $this->path($id));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM attachment'));
        self::assertSame(['expense.created', 'expense.attachment_added', 'expense.deleted'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'expense' ORDER BY at, id"));
    }

    public function testAVendorNamesTheCategoryItsExpensesUsuallyGoTo(): void
    {
        $this->signedIn(['expense.read', 'expense.write', 'vendor.read', 'vendor.write']);
        $fuel = $this->category('Carburant');
        $vendorPath = $this->companyPath().'/vendors/'.$this->vendor->getId()->toRfc4122();
        $body = ['number' => 'FRN-0001', 'name' => 'Sotumag', 'identifiers' => [], 'paymentTermsDays' => 30, 'isActive' => true];

        $this->sendJson('PUT', $vendorPath, [...$body, 'defaultExpenseCategoryId' => '01920000-0000-7000-8000-000000000000']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('defaultExpenseCategoryId', (string) $this->client->getResponse()->getContent());

        $this->sendJson('PUT', $vendorPath, [...$body, 'defaultExpenseCategoryId' => $fuel]);
        self::assertResponseIsSuccessful();
        self::assertSame($fuel, $this->json()['defaultExpenseCategoryId']);
        $this->getJson($this->companyPath().'/expense-options/vendors');
        $vendor = $this->jsonList()[0] ?? null;
        self::assertIsArray($vendor);
        self::assertSame($fuel, $vendor['defaultExpenseCategoryId'] ?? null, 'the picker carries where this vendor\'s expenses go');
    }

    public function testAReaderOnlyReadsAnotherCompanySeesNothingAndASwitchedOffModuleAnswersNotFound(): void
    {
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['expense.read'], 'reader');
        $this->signedIn(['expense.read', 'expense.write', 'company.read', 'company.settings']);
        $this->postJson($this->path(), $this->expense());
        $id = $this->stringAt($this->json(), 'id');
        $this->uploadFile($this->path($id).'/attachments', 'recu.pdf', AttachmentsTest::PDF);
        $attachment = $this->stringAt($this->json(), 'id');

        // Made before the next request: the test client reboots the kernel, and with it the entity manager.
        $globex = $this->createCompany('Globex');
        $their = Expense::create($globex, new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Loyer', '500'), null, null, null, 3, new \DateTimeImmutable());
        $this->em()->persist($their);
        $this->em()->flush();
        $theirs = '/api/companies/'.$globex->getId()->toRfc4122().'/expenses';
        $this->getJson($theirs);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $theirId = $their->getId()->toRfc4122();
        foreach (['GET' => $this->path($theirId), 'GET ' => $this->path($theirId).'/attachments', 'DELETE' => $this->path($theirId)] as $method => $through) {
            $this->sendJson(trim($method), $through);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "$method another company's expense through this company");
        }
        $this->uploadFile($this->path($theirId).'/attachments', 'recu.pdf', AttachmentsTest::PDF);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a file attached to another company’s expense');

        $this->sendJson('PUT', $this->companyPath().'/modules/expenses', ['enabled' => false]);
        self::assertResponseIsSuccessful();
        foreach (['', '/'.$id, '/'.$id.'/attachments', '/'.$id.'/attachments/'.$attachment.'/content'] as $hidden) {
            $this->getJson($this->path().$hidden);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "expenses$hidden while the module is off");
        }
        $this->sendJson('PUT', $this->companyPath().'/modules/expenses', ['enabled' => true]);

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($id).'/attachments');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->path($id).'/attachments/'.$attachment.'/content');
        self::assertResponseIsSuccessful();
        $this->postJson($this->path(), $this->expense());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->uploadFile($this->path($id).'/attachments', 'recu.pdf', AttachmentsTest::PDF);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('DELETE', $this->path($id).'/attachments/'.$attachment);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListIsAPageSearchedNarrowedAndSortedByTheApi(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $fuel = $this->category('Carburant');
        $rent = $this->category('Loyer');
        // The kernel reboots between requests, so the company this case holds is detached by the time it is needed.
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $other = Vendor::create($company, 'FRN-0002', new VendorProfile('Immobilière du Lac'), new \DateTimeImmutable());
        $this->em()->persist($other);
        $this->em()->flush();
        $otherId = $other->getId()->toRfc4122();

        $this->postJson($this->path(), $this->expense(['categoryId' => $fuel]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $recorded = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($recorded).'/record', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path(), $this->expense([
            'date' => '2026-09-12',
            'reference' => 'Q-88',
            'description' => 'Loyer du dépôt',
            'vendorId' => $otherId,
            'categoryId' => $rent,
            'amountNet' => '900',
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path());
        self::assertCount(2, $this->jsonList());
        self::assertSame(2, $this->jsonPage()['totalItems']);

        $this->getJson($this->path().'?itemsPerPage=1');
        self::assertCount(1, $this->jsonList());
        self::assertSame(2, $this->jsonPage()['totalItems']);

        foreach ([
            // The searchable text is the expense's own: what it is for, and the vendor's reference on it.
            'q=loyer' => ['Loyer du dépôt'],
            'q=GASOIL' => ['Gasoil septembre'],
            'q=F-2026-118' => ['Gasoil septembre'],
            'q=zzzz' => [],
            'status=draft' => ['Loyer du dépôt'],
            'status=recorded' => ['Gasoil septembre'],
            'vendorId='.$otherId => ['Loyer du dépôt'],
            'categoryId='.$fuel => ['Gasoil septembre'],
            // The list reads the latest day first, so asking for the oldest is asking for the other end.
            'order[date]=asc' => ['Gasoil septembre', 'Loyer du dépôt'],
            'order[amountGross]=desc&itemsPerPage=1' => ['Loyer du dépôt'],
            'order[vendor]=asc&itemsPerPage=1' => ['Loyer du dépôt'],
        ] as $query => $descriptions) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($descriptions, array_column($this->jsonList(), 'description'), $query);
        }

        // A narrowing the API cannot honour is refused rather than quietly dropped, which would answer the whole list
        // to a request that asked for part of it.
        foreach (['status=spent-ish', 'vendorId=not-an-id', 'categoryId=not-an-id'] as $refused) {
            $this->getJson($this->path().'?'.$refused);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    private function category(string $name, ?string $parentId = null): string
    {
        $this->postJson($this->companyPath().'/expense-categories', ['name' => $name, 'parentId' => $parentId, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $name);

        return $this->stringAt($this->json(), 'id');
    }

    private function taxId(string $code): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->company->getId()->toRfc4122(), $code]);
        self::assertIsString($id, $code);

        return $id;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function expense(array $changes = []): array
    {
        return [...[
            'date' => '2026-09-10',
            'reference' => 'F-2026-118',
            'description' => 'Gasoil septembre',
            'vendorId' => $this->vendor->getId()->toRfc4122(),
            'categoryId' => null,
            'amountNet' => '100.005',
            'taxComponentId' => $this->taxId('TVA19'),
            'notes' => null,
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

    private function path(?string $expenseId = null): string
    {
        return $this->companyPath().'/expenses'.(null === $expenseId ? '' : '/'.$expenseId);
    }
}
