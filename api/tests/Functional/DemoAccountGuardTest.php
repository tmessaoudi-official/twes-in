<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\Infrastructure\Export\ActivityExport;
use App\Demo\Infrastructure\Http\DemoAccountGuard;
use App\ImportExport\Application\Export\ExportForTheBooks;
use App\Module\AccountingExport\Infrastructure\Export\PaymentsJournalExport;
use App\Module\AccountingExport\Infrastructure\Export\PurchasesJournalExport;
use App\Module\AccountingExport\Infrastructure\Export\SalesJournalExport;
use App\Module\AccountingExport\Infrastructure\Export\VatSummaryExport;
use App\Module\Customers\Infrastructure\Export\CustomerExport;
use App\Module\DeliveryNotes\Infrastructure\Export\DeliveryNoteExport;
use App\Module\Expenses\Infrastructure\Export\ExpenseExport;
use App\Module\Inventory\Infrastructure\Export\StockLevelExport;
use App\Module\Inventory\Infrastructure\Export\StockMovementExport;
use App\Module\Invoices\Infrastructure\Export\InvoiceExport;
use App\Module\Products\Infrastructure\Export\ProductExport;
use App\Module\Vendors\Infrastructure\Export\VendorExport;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * A demo visitor never makes the throwaway account a real one nor reaches past the sandbox. Every route about the account
 * itself, or about who belongs to a company and what calls on the platform's operators, is refused to a visitor or
 * allowed on purpose: a new one reds here until it is placed in one list or the other.
 */
final class DemoAccountGuardTest extends ApiTestCase
{
    use MakesSandboxes;
    use RouteSweep;

    /** What a visitor may do with the account and the company, as anyone signed in does. */
    private const array ALLOWED = [
        '_api_/auth/me_get',
        '_api_/companies/{companyId}/closing_get',
        '_api_/companies/{companyId}/members/{userId}_delete',
        '_api_/companies/{companyId}/members_get_collection',
        '_api_/companies/{companyId}/subscription_get',
        '_api_/me/companies_get_collection',
        '_api_/me/company-at-sign-in_put',
        '_api_/me/company_post',
        'api_auth_customer_screen_leave',
        'api_auth_demo',
        'api_auth_demo_availability',
        'api_auth_login',
        'api_auth_logout',
        'api_auth_mfa_passkey_login',
        'api_auth_mfa_passkey_login_options',
        'api_auth_mfa_passkeys',
        'api_auth_mfa_verify',
        'api_auth_password_forgot',
        'api_auth_password_reset',
        'api_auth_sessions',
        'api_auth_sessions_end',
        'api_auth_sessions_end_others',
        'api_auth_step_up',
        'api_auth_step_up_passkey',
        'api_auth_step_up_passkey_options',
        'api_me_notification_preferences',
        'api_me_notification_preferences_change',
        'api_me_notification_read',
        'api_me_notifications',
        'api_me_notifications_read_all',
        'api_me_notifications_unread_by_company',
        'api_me_realtime_token',
        'api_support_access_end',
        'api_support_access_read',
    ];

    /**
     * The account's own routes, and a company's that change who belongs to it, call on the platform's operators or hand
     * an invoice over as Factur-X, a file meant for a real customer's books, or the month's withholding declaration,
     * a file meant for the tax authority's platform.
     */
    private const string SWEPT = '#^/api/(auth/|me/|companies$|companies/\{companyId\}/(members|support-access|close-down|closing|subscription|modules/\{moduleKey\}/interest|invoices/\{invoiceId\}/factur-x|withholding-declarations/))#';

    /** The files an accountant's software takes in as the company's books, refused to a visitor with Factur-X. */
    private const array BOOKS = [
        PaymentsJournalExport::class => 'payments-journal',
        PurchasesJournalExport::class => 'purchases-journal',
        SalesJournalExport::class => 'sales-journal',
        VatSummaryExport::class => 'vat-summary',
    ];

    /** The lists a person reads, which a visitor takes away as from any company. */
    private const array LISTS = [
        ActivityExport::class,
        CustomerExport::class,
        DeliveryNoteExport::class,
        ExpenseExport::class,
        InvoiceExport::class,
        ProductExport::class,
        StockLevelExport::class,
        StockMovementExport::class,
        VendorExport::class,
    ];

    public function testEveryExportIsForTheBooksOrAListOnPurpose(): void
    {
        $declared = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $source = $file instanceof \SplFileInfo && 'php' === $file->getExtension() ? (string) file_get_contents($file->getPathname()) : '';
            if (1 === preg_match('/^namespace ([^;]+);/m', $source, $namespace) && 1 === preg_match('/\bclass (\w+)[^{]*\bimplements [^{]*\b(?:DeclaresExport|ExportForTheBooks)\b/', $source, $class)) {
                $declared[] = $namespace[1].'\\'.$class[1];
            }
        }
        $found = ['books' => [], 'lists' => []];
        foreach ($declared as $export) {
            $found[is_subclass_of($export, ExportForTheBooks::class) ? 'books' : 'lists'][] = $export;
        }
        sort($found['books']);
        sort($found['lists']);
        $placed = ['books' => array_keys(self::BOOKS), 'lists' => self::LISTS];
        sort($placed['books']);
        sort($placed['lists']);

        self::assertGreaterThanOrEqual(10, \count($declared), 'the scan reads the exports, so an empty set cannot pass');
        self::assertSame($placed, $found, 'place a new export among the books or the lists, as it says it is');
    }

    public function testAVisitorIsRefusedTheAccountantsFilesAndTakesAwayTheLists(): void
    {
        $this->readySandbox('TN');
        $this->em()->flush();
        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        self::assertResponseIsSuccessful();
        $company = $this->json()['company'] ?? null;
        $companyId = \is_array($company) && \is_string($company['id'] ?? null) ? $company['id'] : self::fail('The visitor works in the sandbox.');

        $answered = [];
        foreach (self::BOOKS as $subject) {
            foreach (['csv', 'xlsx'] as $format) {
                $this->client->request('GET', \sprintf('/api/companies/%s/exports/%s.%s?from=2026-01-01&to=2026-12-31', $companyId, $subject, $format));
                $answered["$subject.$format"] = [$this->client->getResponse()->getStatusCode(), $this->errorAnswered()];
            }
        }
        // The month's withholding declaration goes to the tax authority's platform as it is, with no band to say demo.
        $this->client->request('GET', \sprintf('/api/companies/%s/withholding-declarations/tej/2026-10', $companyId));
        $answered['tej'] = [$this->client->getResponse()->getStatusCode(), $this->errorAnswered()];
        self::assertSame(array_fill_keys(array_keys($answered), [Response::HTTP_FORBIDDEN, DemoAccountGuard::REFUSED]), $answered);

        $this->client->request('GET', \sprintf('/api/companies/%s/exports/ledgers.csv', $companyId));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nothing by that name is the export\'s own 404');

        $this->client->request('GET', \sprintf('/api/companies/%s/exports/customers.csv', $companyId));
        self::assertResponseIsSuccessful('a list is not the guard\'s to refuse');
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    /** The error a refusal names, or null for a file or a page that is not a refusal. */
    private function errorAnswered(): mixed
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($answer) ? $answer['error'] ?? null : null;
    }

    public function testEveryRouteOfTheAccountIsRefusedOrAllowedOnPurpose(): void
    {
        $swept = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            if (1 === preg_match(self::SWEPT, $route->getPath())) {
                $swept[] = $name;
            }
        }
        sort($swept);
        $placed = [...DemoAccountGuard::REFUSED_ROUTES, ...self::ALLOWED];
        sort($placed);

        self::assertSame($placed, $swept, 'place a new route of the account in the refused list or the allowed one');
    }

    public function testAVisitorIsRefusedWhatWouldMakeTheAccountRealOrReachPastTheSandbox(): void
    {
        $this->readySandbox('TN');
        $this->em()->flush();
        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        self::assertResponseIsSuccessful();
        $company = $this->json()['company'] ?? null;
        $companyId = \is_array($company) && \is_string($company['id'] ?? null) ? $company['id'] : self::fail('The visitor works in the sandbox.');
        $router = static::getContainer()->get(RouterInterface::class);

        $answered = [];
        foreach (DemoAccountGuard::REFUSED_ROUTES as $name) {
            $route = $router->getRouteCollection()->get($name) ?? self::fail("No route $name");
            foreach (self::methodsOf($route) as $method) {
                $this->sendJson($method, self::pathOf($router, $name, $route, ['companyId' => $companyId]), []);
                $answered[$name] = [$this->client->getResponse()->getStatusCode(), $this->json()['error'] ?? null];
            }
        }

        self::assertSame(array_fill_keys(DemoAccountGuard::REFUSED_ROUTES, [Response::HTTP_FORBIDDEN, 'demo_refused']), $answered);
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful('a refusal leaves the visitor signed in');
    }

    public function testSomeoneElseIsNotTheGuardsToRefuse(): void
    {
        $company = $this->createCompany('Quincaillerie');
        $this->createUser('owner@example.com', 'password-1234', $company);
        $this->login('owner@example.com', 'password-1234');

        $this->sendJson('PUT', '/api/auth/password', ['currentPassword' => 'wrong-password', 'newPassword' => 'another-password-5678']);

        self::assertNotSame('demo_refused', $this->json()['error'] ?? null);
        self::assertNotSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }
}
