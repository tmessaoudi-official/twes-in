<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A settings page saves every key it changed in one go (docs/SPEC.md § 7, 2026-10-17 03:44): each key sends the
 * version its row held at that level when read, 0 for none, and a key saved, created or reset since refuses the whole
 * save, so nothing is kept of it.
 */
final class SettingChangesTest extends ApiTestCase
{
    private const string TERMS = 'document.payment_terms_days';
    private const string VALIDITY = 'quote.validity_days';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        $this->createUser('admin@twes.local', 'password-1234', $this->company, ['company.read', 'company.settings'], 'admin');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testEveryKeyChangedIsSavedTogetherAndEachLevelReadsItsVersion(): void
    {
        self::assertSame([], $this->versionsOf(self::TERMS), 'nothing stored, no version');

        $this->save([[self::TERMS, 45, 0], [self::VALIDITY, 20, 0]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([45, ['company' => 1]], [$this->valueOf(self::TERMS), $this->versionsOf(self::TERMS)]);
        self::assertSame([20, ['company' => 1]], [$this->valueOf(self::VALIDITY), $this->versionsOf(self::VALIDITY)]);

        $this->save([[self::TERMS, 60, 1]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([60, ['company' => 2]], [$this->valueOf(self::TERMS), $this->versionsOf(self::TERMS)]);
    }

    public function testAKeySavedSinceItWasReadRefusesTheWholeSave(): void
    {
        $this->save([[self::TERMS, 45, 0]]);

        // The page read neither value; the terms were saved since, so the validity is not kept either.
        $this->save([[self::VALIDITY, 20, 0], [self::TERMS, 60, 0]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        self::assertSame([45, 30], [$this->valueOf(self::TERMS), $this->valueOf(self::VALIDITY)]);
    }

    public function testAKeyChangedSinceItWasReadRefusesTheSave(): void
    {
        $this->save([[self::TERMS, 45, 0]]);
        $this->save([[self::TERMS, 50, 1]]);

        $this->save([[self::TERMS, 60, 1]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(50, $this->valueOf(self::TERMS));
    }

    public function testAKeyResetSinceItWasReadRefusesTheSave(): void
    {
        $this->save([[self::TERMS, 45, 0]]);
        $this->sendJson('DELETE', $this->path().'/'.self::TERMS.'?level=company');
        self::assertResponseIsSuccessful();

        $this->save([[self::TERMS, 60, 1]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame([30, []], [$this->valueOf(self::TERMS), $this->versionsOf(self::TERMS)]);
    }

    public function testAValueRefusedRefusesTheWholeSave(): void
    {
        $this->save([[self::VALIDITY, 20, 0], [self::TERMS, 'soon', 0]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([30, 30], [$this->valueOf(self::TERMS), $this->valueOf(self::VALIDITY)]);
    }

    public function testEachChangeSaysWhichVersionItRevises(): void
    {
        $this->sendJson('PUT', $this->path(), ['level' => 'company', 'changes' => [['key' => self::TERMS, 'value' => 45]]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['changes[0].version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    public function testAKeyIsChangedOnceInASave(): void
    {
        $this->save([[self::TERMS, 45, 0], [self::TERMS, 60, 0]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(30, $this->valueOf(self::TERMS));
    }

    public function testASaveChangesSomething(): void
    {
        $this->sendJson('PUT', $this->path(), ['level' => 'company', 'changes' => []]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAMemberWithoutTheRightChangesNoCompanyDefault(): void
    {
        $this->createUser('member@twes.local', 'password-1234', $this->company, ['company.read'], 'member');
        $this->login('member@twes.local', 'password-1234');

        $this->save([[self::TERMS, 45, 0]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(30, $this->valueOf(self::TERMS));
    }

    public function testACompanyTheCallerHasNothingToDoWithLooksAbsent(): void
    {
        $globex = $this->createCompany('Globex');

        $this->sendJson('PUT', '/api/companies/'.$globex->getId()->toRfc4122().'/settings', ['level' => 'company', 'changes' => [['key' => self::TERMS, 'value' => 45, 'version' => 0]]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<array{string, mixed, int}> $changes */
    private function save(array $changes): void
    {
        $this->sendJson('PUT', $this->path(), [
            'level' => 'company',
            'changes' => array_map(static fn (array $change): array => ['key' => $change[0], 'value' => $change[1], 'version' => $change[2]], $changes),
        ]);
    }

    private function valueOf(string $key): mixed
    {
        return $this->row($key)['value'];
    }

    /** @return array<mixed> */
    private function versionsOf(string $key): array
    {
        $versions = $this->row($key)['versions'] ?? [];
        self::assertIsArray($versions);

        return $versions;
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        $status = $this->client->getResponse()->getStatusCode();
        $this->getJson($this->path().'?chain=parties');
        self::assertResponseIsSuccessful();
        foreach ($this->jsonList() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail("No setting $key in the response (the last call before answered $status).");
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/settings';
    }
}
