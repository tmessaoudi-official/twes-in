<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform operator writes each legal page per language, a new version each time, and validates the latest once
 * checked (docs/SPEC.md § 8 row 148). Nobody else may; anyone reads the result at /api/legal.
 */
final class PlatformLegalTextsTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';
    private const string VERSIONS = '/api/platform/legal-texts/mentions/fr/versions';

    public function testAnOperatorWritesANewVersionWhichAnyoneReadsAsADraft(): void
    {
        $operator = $this->signedInAsOperator();

        $this->postJson(self::VERSIONS, ['body' => "## Éditeur\n\ntwes"]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $written = $this->json();
        self::assertSame("## Éditeur\n\ntwes", $written['body']);
        self::assertFalse($written['validated']);
        self::assertSame('op@twes.local', $written['createdBy']);
        self::assertIsString($written['id']);
        self::assertSame(['legal_text.written'], $this->auditedActions($operator));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/legal/mentions/fr', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame("## Éditeur\n\ntwes", $this->json()['body']);
        self::assertFalse($this->json()['validated']);
    }

    public function testValidatingMarksTheLatestVersionAndTheHistoryKeepsTheOlderOnes(): void
    {
        $operator = $this->signedInAsOperator();
        $this->postJson(self::VERSIONS, ['body' => 'first']);
        $this->postJson(self::VERSIONS, ['body' => 'second']);

        $this->postJson('/api/platform/legal-texts/mentions/fr/validate', []);

        self::assertResponseIsSuccessful();
        self::assertSame('second', $this->json()['body']);
        self::assertTrue($this->json()['validated']);
        self::assertSame('op@twes.local', $this->json()['validatedBy']);
        $this->getJson(self::VERSIONS);
        self::assertSame(['second', 'first'], array_column($this->jsonList(), 'body'));
        self::assertSame([true, false], array_column($this->jsonList(), 'validated'));
        self::assertEqualsCanonicalizing(['legal_text.written', 'legal_text.written', 'legal_text.validated'], $this->auditedActions($operator));
    }

    public function testTheOverviewNamesEveryPageInEveryLanguage(): void
    {
        $this->signedInAsOperator();
        $this->postJson('/api/platform/legal-texts/cookies/ar/versions', ['body' => 'نص']);

        $this->getJson('/api/platform/legal-texts');

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertCount(27, $rows);
        $cookies = array_values(array_filter($rows, static fn (array $row) => 'cookies' === $row['page'] && 'ar' === $row['language']));
        self::assertTrue($cookies[0]['written']);
        self::assertFalse($cookies[0]['validated']);
        $security = array_values(array_filter($rows, static fn (array $row) => 'security' === $row['page'] && 'ar' === $row['language']));
        self::assertFalse($security[0]['written']);
        self::assertNull($security[0]['publishedOn']);
    }

    public function testAnEmptyTextAnUnknownPageAndValidatingNothingAreRefused(): void
    {
        $this->signedInAsOperator();

        $this->postJson(self::VERSIONS, ['body' => "  \n"]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson('/api/platform/legal-texts/nothing/fr/versions', ['body' => 'x']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson('/api/platform/legal-texts/security/ar/validate', []);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testNobodyButAnOperatorWritesOrValidates(): void
    {
        $this->createUser('owner@acme.test', self::PASSWORD, $this->createCompany('Acme'));
        $this->login('owner@acme.test', self::PASSWORD);

        $this->postJson(self::VERSIONS, ['body' => 'mine']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->postJson('/api/platform/legal-texts/mentions/fr/validate', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->getJson('/api/platform/legal-texts');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function signedInAsOperator(): User
    {
        $this->client->getCookieJar()->clear();
        $operator = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('op@twes.local')])
            ?? $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        return $operator;
    }

    /** @return list<string> */
    private function auditedActions(User $operator): array
    {
        /** @var list<string> $actions */
        $actions = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE actor_user_id = ? AND action LIKE 'legal_text.%'",
            [$operator->getId()->toRfc4122()],
        );

        return $actions;
    }
}
