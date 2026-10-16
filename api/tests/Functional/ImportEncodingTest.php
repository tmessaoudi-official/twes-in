<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Module\Customers\Domain\Customer;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Infrastructure\Spreadsheet\OpenSpoutWriter;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A CSV is read in the encoding it was written in, found on the whole file or chosen by the person, and the answer says
 * which; a row that encoding cannot read is refused on its line, never a 500 (docs/SPEC.md § 7, 2026-10-15 20:00, Round 1
 * Q12: « offer the file's encoding »).
 */
final class ImportEncodingTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'customer.write'], 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** French Excel's CSV whose first pages hold no accent: judged on its first 8 KB, it was read as UTF-8 and answered 500. */
    public function testAFileWhoseAccentsBeginPastItsFirstPagesIsReadInItsOwnEncoding(): void
    {
        $rows = "number,name,kind\n";
        for ($i = 1; \strlen($rows) < 9000; ++$i) {
            $rows .= \sprintf("CLI-%04d,Client numero %d,individual\n", $i, $i);
        }
        $rows .= "CLI-9999,Société Générale,individual\n";

        $this->import(mb_convert_encoding($rows, 'Windows-1252', 'UTF-8'));

        self::assertResponseIsSuccessful();
        self::assertSame('windows-1252', $this->json()['encoding'] ?? null);
        self::assertSame('Société Générale', $this->nameOf('CLI-9999'));
    }

    /** What Excel saves as « Texte Unicode »: UTF-16 with its byte-order mark; « ج » holds the byte of a comma. */
    public function testAUtf16FileIsReadByItsByteOrderMarkWithoutSplittingALetterOnADelimitersByte(): void
    {
        $this->import("\xFF\xFE".mb_convert_encoding("number,name,kind\nCLI-0001,شركة الجنوب,individual\n", 'UTF-16LE', 'UTF-8'));

        self::assertResponseIsSuccessful();
        self::assertSame('utf-16', $this->json()['encoding'] ?? null);
        self::assertSame('شركة الجنوب', $this->nameOf('CLI-0001'));
    }

    /** A Tunisian Excel CSV in Arabic is Windows-1256, never valid UTF-8; an Arabic word tells it from a French file. */
    public function testAnArabicFileIsFoundToBeWindows1256(): void
    {
        $this->import($this->windows1256("number,name,kind\nCLI-0001,شركة الجنوب,individual\nCLI-0002,Quincaillerie Ben Salah,individual\n"));

        self::assertResponseIsSuccessful();
        self::assertSame('windows-1256', $this->json()['encoding'] ?? null);
        self::assertSame('شركة الجنوب', $this->nameOf('CLI-0001'));
    }

    public function testAFileIsReadInTheEncodingThePersonChose(): void
    {
        // Two letters standing alone read as French accents (« á ä »); the person knows the file is Arabic.
        $this->import($this->windows1256("number,name,kind\nCLI-0001,ل ن,individual\n"), ['encoding' => 'windows-1256']);

        self::assertResponseIsSuccessful();
        self::assertSame('windows-1256', $this->json()['encoding'] ?? null);
        self::assertSame('ل ن', $this->nameOf('CLI-0001'));
    }

    public function testARowTheChosenEncodingCannotReadIsRefusedOnItsLineNamingNoCell(): void
    {
        $this->import(mb_convert_encoding("number,name,kind\nCLI-0001,Carthage,individual\nCLI-0002,Société,individual\n", 'Windows-1252', 'UTF-8'), ['encoding' => 'utf-8', 'dryRun' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame('utf-8', $this->json()['encoding'] ?? null);
        self::assertSame(
            [['line' => 3, 'column' => null, 'code' => 'invalid_encoding', 'params' => [], 'message' => 'The row holds bytes the encoding the file is read in cannot read.']],
            $this->json()['rejected'],
            'named by its line alone, none of its bytes echoed',
        );
        self::assertSame([2], $this->json()['created']);
    }

    public function testAHeaderTheChosenEncodingCannotReadRefusesTheFileNamingNoColumn(): void
    {
        $this->import(mb_convert_encoding("number,name,kind,catégorie\nCLI-0001,Carthage,individual,x\n", 'Windows-1252', 'UTF-8'), ['encoding' => 'utf-8', 'dryRun' => '1']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('unreadable_encoding', $this->json()['error']);
        self::assertSame([], $this->json()['columns']);
        self::assertSame('utf-8', $this->json()['encoding'] ?? null);
    }

    public function testAnEncodingNobodyOffersIsRefused(): void
    {
        $this->import("number,name,kind\nCLI-0001,Carthage,individual\n", ['encoding' => 'ebcdic']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_request', $this->json()['error']);
    }

    public function testAWorkbookHasNoEncodingAndIgnoresOneChosen(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'import-test-');
        (new OpenSpoutWriter())->write($path, SpreadsheetFormat::Xlsx, [['number', 'name', 'kind'], ['CLI-0009', 'Société', 'individual']]);
        $contents = (string) file_get_contents($path);
        unlink($path);

        $this->import($contents, ['encoding' => 'windows-1256'], 'customers.xlsx');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('encoding', $this->json());
        self::assertNull($this->json()['encoding']);
        self::assertSame('Société', $this->nameOf('CLI-0009'));
    }

    /** @param array<string, string> $fields */
    private function import(string $contents, array $fields = [], string $name = 'customers.csv'): void
    {
        $this->uploadFile('/api/companies/'.$this->company->getId()->toRfc4122().'/imports/customers', $name, $contents, 'file', ['mode' => 'create', 'dryRun' => '0', ...$fields]);
    }

    private function windows1256(string $utf8): string
    {
        $bytes = iconv('UTF-8', 'CP1256', $utf8);
        self::assertIsString($bytes);

        return $bytes;
    }

    private function nameOf(string $number): ?string
    {
        return $this->em()->getRepository(Customer::class)->findOneBy(['company' => $this->company, 'number' => $number])?->getProfile()->name;
    }
}
