<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Shared\Application\ListLimits;
use App\Shared\Infrastructure\Validation\ListLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * A document's lines are at most `ListLimits::DOCUMENT_LINES` (`app.list_limits`), refused before any line is read: a
 * request of 10,000 lines held a worker for 15 seconds, and its preview, typed again and again, for 4.
 */
final class DocumentLineLimitTest extends ApiTestCase
{
    private const array WRITER = ['company.read', 'invoice.read', 'invoice.write', 'quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write', 'purchase.read', 'purchase.write', 'expense.read', 'expense.write'];

    private string $companyPath;

    protected function setUp(): void
    {
        parent::setUp();
        $company = $this->createCompany('Acme', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($company);
        $this->companyPath = '/api/companies/'.$company->getId()->toRfc4122();
        $this->createUser('writer@twes.local', 'password-1234', $company, self::WRITER, 'member');
        $this->login('writer@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        foreach (['invoices', 'invoices/preview', 'quotes', 'quotes/preview', 'delivery-notes', 'delivery-notes/preview', 'purchase-orders', 'expenses'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('documents')]
    public function testOneLineMoreThanTheLimitIsRefusedOnTheLines(string $path): void
    {
        $most = static::getContainer()->get(ListLimits::class)->most(ListLimits::DOCUMENT_LINES);

        $this->postJson($this->companyPath.'/'.$path, ['lines' => array_fill(0, $most + 1, ['description' => 'Vis'])]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertContains(['lines', ListLimit::TOO_MANY, 'too_many_entries'], $this->violations(), 'the list itself is refused, under its reason');
    }

    #[DataProvider('documents')]
    public function testAtTheLimitTheLinesAreNotRefusedForTheirNumber(string $path): void
    {
        $most = static::getContainer()->get(ListLimits::class)->most(ListLimits::DOCUMENT_LINES);

        $this->postJson($this->companyPath.'/'.$path, ['lines' => array_fill(0, $most, ['description' => 'Vis'])]);

        self::assertNotContains(['lines', ListLimit::TOO_MANY, 'too_many_entries'], $this->violations());
    }

    /** @return list<array{mixed, mixed, mixed}> each violation's path, code and reason */
    private function violations(): array
    {
        $violations = $this->json()['violations'] ?? [];
        self::assertIsArray($violations);

        return array_values(array_map(static function (mixed $violation): array {
            self::assertIsArray($violation);
            $payload = $violation['payload'] ?? null;

            return [$violation['propertyPath'] ?? null, $violation['code'] ?? null, \is_array($payload) ? ($payload['reason'] ?? null) : null];
        }, $violations));
    }
}
