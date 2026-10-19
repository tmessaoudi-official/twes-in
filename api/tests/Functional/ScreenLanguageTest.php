<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The words the API itself puts on a screen (the preset's identifier and regime labels) are in the language the screen
 * asks for, never the one last saved on the account: someone who went back to the company's French after trying Arabic
 * keeps « ar » on the account, for the mails, and read Arabic labels on every French screen.
 */
final class ScreenLanguageTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        $user = $this->createUser('owner@twes.local', 'password-1234', $this->company);
        // The account kept the last language chosen; the screen now reads French.
        $user->changeLocale('ar');
        $this->em()->flush();
        $this->login('owner@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string, string, string, string}> path under the company, label path, fr, en */
    public static function screens(): iterable
    {
        yield 'customer options' => ['customer-options', 'identifiers.0.label', 'Matricule fiscal', 'Tax identification number (matricule fiscal)'];
        yield 'customer regimes in the customer options' => ['customer-options', 'regimes.0.label', 'Régime normal', 'Standard'];
        yield 'vendor options' => ['vendor-options', 'identifiers.0.label', 'Matricule fiscal', 'Tax identification number (matricule fiscal)'];
        yield 'company profile' => ['profile', 'identifierFields.0.label', 'Matricule fiscal', 'Tax identification number (matricule fiscal)'];
        yield 'company VAT regimes' => ['profile', 'vatRegimes.0.label', 'Régime normal', 'Standard'];
        yield 'customer tax regimes' => ['customer-tax-regimes', '0.label', 'Régime normal', 'Standard'];
        yield 'customer import guide' => ['imports/customers', 'columns.@matricule_fiscal.label', 'Matricule fiscal', 'Tax identification number (matricule fiscal)'];
    }

    #[DataProvider('screens')]
    public function testTheScreenGetsTheLanguageItAsksFor(string $path, string $label, string $french, string $english): void
    {
        self::assertSame($french, $this->labelIn('fr', $path, $label));
        self::assertSame($english, $this->labelIn('en', $path, $label));
    }

    /** One worker serves both requests, as FrankenPHP does: the second never answers in the first one's language. */
    public function testEachRequestOnOneKernelKeepsItsOwnLanguage(): void
    {
        $this->client->disableReboot();

        self::assertSame('المعرّف الجبائي', $this->labelIn('ar', 'customer-options', 'identifiers.0.label'));
        self::assertSame('Matricule fiscal', $this->labelIn('fr', 'customer-options', 'identifiers.0.label'));
    }

    public function testARequestNamingNoLanguageReadsTheDefaultNotTheAccounts(): void
    {
        self::assertSame('Matricule fiscal', $this->labelIn('', 'customer-options', 'identifiers.0.label'));
    }

    private function labelIn(string $language, string $path, string $label): mixed
    {
        $this->client->request('GET', $this->path($path), [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => $language]);
        self::assertResponseIsSuccessful();
        $value = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        foreach (explode('.', $label) as $step) {
            self::assertIsArray($value);
            if (str_starts_with($step, '@')) {
                $rows = array_values(array_filter($value, static fn (mixed $row): bool => \is_array($row) && substr($step, 1) === ($row['key'] ?? null)));
                self::assertCount(1, $rows, $step);
                $value = $rows[0];
                continue;
            }
            // A collection read as JSON-LD lists its rows under « member ».
            if (!\array_key_exists($step, $value) && \array_key_exists('member', $value)) {
                $value = $value['member'];
                self::assertIsArray($value);
            }
            $value = $value[$step] ?? null;
        }

        return $value;
    }

    private function path(string $path): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$path;
    }
}
