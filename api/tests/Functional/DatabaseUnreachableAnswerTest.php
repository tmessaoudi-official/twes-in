<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Files\Application\FileStorage;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A database that stops answering in the middle of a request reaches the person as the web reads it (`isUnreachable`):
 * 503 with a Retry-After and the type `/problems/database-unreachable`, with debug on or off, and none of the driver's
 * words, which name the host. Here through a company's logo, a request whose work is cut before it commits.
 */
final class DatabaseUnreachableAnswerTest extends ApiTestCase
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
    public function testAConnectionCutMidRequestAnswersUnreachableToBeSentAgain(bool $debug): void
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
        // A fresh container whose storage meets a cut connection, kept for the upload: signing in built the real one.
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        static::getContainer()->set(FileStorage::class, new class implements FileStorage {
            public function write(string $key, string $contents): void
            {
                throw new ConnectionLost(new class('SQLSTATE[57P01]: terminating connection due to administrator command on db.internal', '57P01') extends AbstractException { }, null);
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

        self::assertResponseStatusCodeSame(503);
        self::assertSame('5', $this->client->getResponse()->headers->get('Retry-After'), 'sent again a moment later');
        $answer = $this->json();
        self::assertSame('/problems/database-unreachable', $answer['type'] ?? null);
        self::assertSame(503, $answer['status'] ?? null);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('db.internal', $body, 'the host stays out of the answer');
        self::assertStringNotContainsString('SQLSTATE', $body);
    }
}
