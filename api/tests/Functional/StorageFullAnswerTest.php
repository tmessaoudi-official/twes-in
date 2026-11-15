<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Files\Application\FileStorage;
use App\Shared\Application\Storage\StorageFull;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A write the disk has no room for reaches the person as the web reads it (`storage-full-interceptor.ts`): 507 and the
 * type `/problems/storage-full`, with debug on or off, the screen saying it in its own texts; off debug the detail is
 * the status's words, as for every 5xx. Here through a company's logo, a file written on a request.
 */
final class StorageFullAnswerTest extends ApiTestCase
{
    /** A 1x1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @return iterable<string, array{bool}> */
    public static function debugs(): iterable
    {
        yield 'debug on, as development runs' => [true];
        yield 'debug off, as a shop runs' => [false];
    }

    #[DataProvider('debugs')]
    public function testAFileTheDiskHasNoRoomForAnswersStorageFullInWords(bool $debug): void
    {
        if (!$debug) {
            self::ensureKernelShutdown();
            $this->client = static::createClient(['debug' => false]);
            $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
            $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        }
        $company = $this->createCompany('Acme');
        $this->createUser('admin@twes.local', 'password-1234', $company, ['company.read', 'company.settings'], 'admin');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        // A fresh container, whose storage has no room left, kept for the upload: signing in built the real one.
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        static::getContainer()->set(FileStorage::class, new class implements FileStorage {
            public function write(string $key, string $contents): void
            {
                throw new StorageFull(\sprintf('The storage has no room left for %s.', $key));
            }

            public function read(string $key): string
            {
                throw new \LogicException('Nothing is read here.');
            }

            public function delete(string $key): void
            {
            }

            public function keysUnder(string $prefix): iterable
            {
                return [];
            }
        });

        $this->uploadFile('/api/companies/'.$company->getId()->toRfc4122().'/logo', 'logo.png', (string) base64_decode(self::PNG, true));

        self::assertResponseStatusCodeSame(507);
        $answer = $this->json();
        self::assertSame('/problems/storage-full', $answer['type'] ?? null);
        self::assertSame(507, $answer['status'] ?? null);
        // Off debug, API Platform puts the status's own words in a 5xx's detail, as a server error's message may say what
        // it should not: the screen says it from the type, its own texts.
        self::assertSame($debug ? "The installation's disk is full: this could not be done, and nothing was saved. Tell whoever runs the installation, then try again." : 'Internal Server Error', $answer['detail'] ?? null);
        self::assertStringNotContainsString('companies/', (string) $this->client->getResponse()->getContent(), 'the path stays out of the answer');
    }
}
