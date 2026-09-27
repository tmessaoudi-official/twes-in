<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;

/** docs/SPEC.md § 8 row 148: a legal page is read by anyone, signed in or not. */
final class LegalTextsTest extends ApiTestCase
{
    public function testAnyoneReadsAPagesLatestVersionWithoutASession(): void
    {
        $this->write(LegalPage::Cookies, LegalLanguage::En, 'old', '2026-09-01 10:00');
        $latest = $this->write(LegalPage::Cookies, LegalLanguage::En, "# Cookies\n\nWhat is stored.", '2026-09-20 10:00');
        $latest->validate('operator@twes.local', new \DateTimeImmutable('2026-09-21 10:00'));
        $this->em()->flush();

        $this->client->request('GET', '/api/legal/cookies/en', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"values":{}', (string) $this->client->getResponse()->getContent(), 'an object even when empty');
        self::assertSame([
            'page' => 'cookies',
            'language' => 'en',
            'body' => "# Cookies\n\nWhat is stored.",
            'publishedOn' => '2026-09-20',
            'validated' => true,
            'values' => [],
        ], $this->body());
    }

    public function testAPageMissingInTheLanguageAskedForAnswersItsFrenchVersionAndSaysSo(): void
    {
        $this->write(LegalPage::Cookies, LegalLanguage::Fr, 'Témoins', '2026-09-20 10:00');

        $this->client->request('GET', '/api/legal/cookies/ar', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $this->body()['language']);
        self::assertFalse($this->body()['validated']);
    }

    public function testAnUnknownPageAnUnknownLanguageAndAPageNeverWrittenAreNotFound(): void
    {
        $this->write(LegalPage::Cookies, LegalLanguage::Fr, 'Témoins', '2026-09-20 10:00');

        foreach (['/api/legal/nothing/fr', '/api/legal/cookies/de', '/api/legal/privacy/fr'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testThePublishersIdentityTheOperatorSetFillsThePlaceholdersAndNothingElseIsSent(): void
    {
        $this->write(LegalPage::Mentions, LegalLanguage::Fr, 'Éditeur : {{publisher.name}}', '2026-09-20 10:00');
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        $this->sendJson('PUT', '/api/platform/settings/legal.publisher.name', ['value' => 'twes SAS']);
        self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/legal/mentions/fr', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame(['publisher.name' => 'twes SAS'], $this->body()['values'], 'only what was filled in, by its placeholder');
        self::assertSame('Éditeur : {{publisher.name}}', $this->body()['body'], 'the text itself is kept as written');
    }

    private function write(LegalPage $page, LegalLanguage $language, string $body, string $at): LegalText
    {
        $text = LegalText::draft($page, $language, $body, null, new \DateTimeImmutable($at));
        $this->em()->persist($text);
        $this->em()->flush();

        return $text;
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }
}
