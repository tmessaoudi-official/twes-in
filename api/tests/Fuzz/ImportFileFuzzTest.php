<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Fuzz;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\ImportExport\Application\ImportCatalogue;
use App\Shared\Application\Spreadsheet\UnreadableSpreadsheet;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every importable subject over the hostile files of the corpus, through the import a person sends: never a 500, a
 * file refused leaves every table as it found it, and a declared document type is refused as one.
 *
 * Each file is previewed, then imported, as the screen does. The preview is the oracle of the import: a file whose
 * preview found anything to refuse must be refused whole, so an import that kept its good rows and dropped the rest
 * would change a table and go red here, whatever its own answer says.
 */
final class ImportFileFuzzTest extends ApiTestCase
{
    /** The subject the files broken below the row are sent to: no header saves them, so one subject reads them all. */
    private const string FILE_LEVEL_SUBJECT = 'customers';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Fuzz');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->login('owner@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        // The session is stored once the request after the sign-in ends: done here, so no file is blamed for its row.
        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/imports/'.self::FILE_LEVEL_SUBJECT);
        self::assertResponseIsSuccessful();
    }

    public function testNoHostileFileAnswers500OrWritesHalfAnImport(): void
    {
        $catalogue = static::getContainer()->get(ImportCatalogue::class);
        $keys = $catalogue->keys();
        self::assertContains(self::FILE_LEVEL_SUBJECT, $keys);

        foreach ($keys as $key) {
            // The header a person's file would have, read as the screen reads it. A subject whose module is off would
            // answer 404 to every file and leave the test green on nothing.
            $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/imports/'.$key);
            self::assertResponseIsSuccessful($key);
            $columns = $this->json()['columns'] ?? null;
            self::assertIsArray($columns, $key);
            $header = array_map(static fn (mixed $column): string => \is_array($column) && \is_string($column['key'] ?? null) ? $column['key'] : '', array_values($columns));
            self::assertNotContains('', $header, $key);
            $files = ImportFuzzCorpus::rowFiles($header);
            if (self::FILE_LEVEL_SUBJECT === $key) {
                $files = [...$files, ...ImportFuzzCorpus::fileFiles()];
                foreach (ImportFuzzCorpus::seeds() as $seed) {
                    $files = [...$files, ...ImportFuzzCorpus::seededFiles($seed)];
                }
            }
            foreach ($files as $file) {
                $this->sendTwice($key, $file);
            }
        }
    }

    private function sendTwice(string $subject, FuzzFile $file): void
    {
        $named = $subject.' / '.$file->name;
        $before = $this->counts();

        $this->send($subject, $file, true);
        $preview = $this->answer($named);
        self::assertSame($before, $this->counts(), $named.': a preview writes nothing');
        if ($file->declaresDoctype) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $preview['status'], $named);
            self::assertSame(UnreadableSpreadsheet::DOCUMENT_TYPE, $preview['body']['error'] ?? null, $named);
        }
        if (null === $file->format()) {
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $preview['status'], $named);
            $violations = $preview['body']['violations'] ?? null;
            self::assertIsArray($violations, $named.': a format the import does not read is refused on the file');
            $violation = $violations[0] ?? null;
            self::assertIsArray($violation, $named);
            $payload = $violation['payload'] ?? null;
            self::assertIsArray($payload, $named);
            self::assertSame(['file', 'file_format'], [$violation['propertyPath'] ?? null, $payload['reason'] ?? null], $named.': a format the import does not read is refused by its name, on the file');
        }

        $this->send($subject, $file, false);
        $import = $this->answer($named);
        $refused = isset($preview['body']['error']) || [] !== ($preview['body']['rejected'] ?? []);
        if ($refused || Response::HTTP_OK !== $import['status']) {
            // The tables first: what the import kept is the guarantee, whatever its answer says.
            self::assertSame($before, $this->counts(), $named.': a file refused leaves every table as it was');
            self::assertNotSame(Response::HTTP_OK, $import['status'], $named.': a file its preview refused is refused');
        }
    }

    private function send(string $subject, FuzzFile $file, bool $dryRun): void
    {
        $this->uploadFile('/api/companies/'.$this->company->getId()->toRfc4122().'/imports/'.$subject, $file->filename, $file->bytes, 'file', ['mode' => 'upsert', 'dryRun' => $dryRun ? '1' : '0']);
    }

    /** @return array{status: int, body: array<array-key, mixed>} */
    private function answer(string $named): array
    {
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        self::assertContains($status, [Response::HTTP_OK, Response::HTTP_FORBIDDEN, Response::HTTP_UNPROCESSABLE_ENTITY], \sprintf('%s answered %d: %s', $named, $status, substr((string) $response->getContent(), 0, 600)));
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body, $named);

        return ['status' => $status, 'body' => $body];
    }

    /**
     * The rows of every table, read in one statement: a write anywhere, not only in the subject's own table, is seen.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $connection = static::getContainer()->get(Connection::class);
        $tables = Rows::texts($connection->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name"));
        $select = implode(', ', array_map(static fn (string $table): string => \sprintf('(SELECT count(*) FROM %1$s) AS %1$s', $connection->quoteSingleIdentifier($table)), $tables));
        $counts = [];
        foreach (Rows::row($connection->fetchAssociative('SELECT '.$select)) as $table => $count) {
            $counts[(string) $table] = Rows::int($count);
        }

        return $counts;
    }
}
