<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Scanning\Domain\ScanPairing;
use App\Shared\Application\RealtimePublisher;
use App\Shared\Infrastructure\Realtime\HmacJwt;
use App\Tenancy\Domain\Company;
use App\Tests\Support\RecordingRealtimePublisher;
use Symfony\Component\HttpFoundation\Response;

/**
 * A phone lent to a computer tab as a scanner, over HTTP (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): the tab opens
 * a link while signed in, the phone claims it with no session at all, and from then on its key is all it presents.
 */
final class ScanPairingTest extends ApiTestCase
{
    private RecordingRealtimePublisher $publisher;
    private Company $company;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $this->publisher);
        $this->seedBuiltInRoles();
        $this->company = $this->createCompany('Acme');
        $this->userId = $this->createUser('till@twes.local', 'password-1234', $this->company, ['product.read'], 'member')->getId()->toRfc4122();
        $this->login('till@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testThePhoneClaimsTheLinkWithNoSessionAndItsScansReachTheTab(): void
    {
        [$id, $link] = $this->open();

        $key = $this->claim($link);
        $this->postJson("/api/scan-pairings/$id/scans", ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001'], server: ['HTTP_X_PAIRING_KEY' => $key]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame(
            ['channel' => 'user:'.$this->userId, 'data' => ['type' => 'pairing', 'event' => 'scan', 'pairing' => $id, 'tab' => 'tab-1', 'scan' => '0199aaaa-0000-4000-8000-000000000001', 'code' => '3017620422003']],
            $this->publisher->last(),
        );
    }

    public function testTheLinkIsSingleUse(): void
    {
        [, $link] = $this->open();
        $this->claim($link);

        $this->phone();
        $this->postJson('/api/scan-pairings/claim', ['link' => $link]);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSame('claimed', $this->json()['error']);
    }

    public function testAScanWithoutTheKeyIsRefusedAndReachesNobody(): void
    {
        [$id, $link] = $this->open();
        $this->claim($link);
        $before = \count($this->publisher->pushed);

        $this->postJson("/api/scan-pairings/$id/scans", ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001'], server: ['HTTP_X_PAIRING_KEY' => str_repeat('0', 64)]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->postJson("/api/scan-pairings/$id/scans", ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertCount($before, $this->publisher->pushed);
    }

    public function testTheTabEchoesToThePhonesChannelAndTheChoiceComesBack(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $this->login('till@twes.local', 'password-1234');

        $this->postJson($this->pairingPath($id).'/echo', [
            'id' => '0199aaaa-0000-4000-8000-00000000000e',
            'scan' => null,
            'outcome' => 'unclaimed',
            'message' => 'products.scan.unknown',
            'params' => [],
            'product' => null,
            'choices' => [['id' => 'create', 'label' => 'products.scan.actions.create']],
        ], server: ['HTTP_X_TAB' => 'tab-1']);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame('scan:'.$id, $this->publisher->last()['channel']);

        $this->phone();
        $this->postJson("/api/scan-pairings/$id/choices", ['echo' => '0199aaaa-0000-4000-8000-00000000000e', 'choice' => 'create'], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertSame('choice', $this->publisher->last()['data']['event']);
    }

    public function testAMalformedEchoIsRefused(): void
    {
        [$id] = $this->open();

        $this->postJson($this->pairingPath($id).'/echo', ['id' => 'x', 'outcome' => 'done', 'message' => 'scan.added', 'params' => [], 'product' => ['name' => 'N', 'price' => '1', 'cost' => '0.4'], 'choices' => []]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testThePhonesTokenHearsItsPairingAlone(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);

        $this->postJson("/api/scan-pairings/$id/realtime-token", null, server: ['HTTP_X_PAIRING_KEY' => $key]);

        self::assertResponseIsSuccessful();
        $claims = json_decode(HmacJwt::base64UrlDecode(explode('.', $this->stringAt($this->json(), 'token'))[1]), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertSame(['scan:'.$id], $claims['channels']);
    }

    public function testClosingTheTabEndsThePhonesUse(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $this->login('till@twes.local', 'password-1234');

        $this->sendJson('DELETE', $this->pairingPath($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->phone();
        $this->postJson("/api/scan-pairings/$id/scans", ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001'], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSame('ended', $this->json()['error']);
    }

    public function testSigningOutEndsThePhonesUse(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $this->login('till@twes.local', 'password-1234');

        $this->postJson('/api/auth/logout', null);

        $this->phone();
        $this->postJson("/api/scan-pairings/$id/scans", ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001'], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    public function testTheTabKeepsThePairingAlive(): void
    {
        [$id] = $this->open();

        $this->postJson($this->pairingPath($id).'/heartbeat', null);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testOpeningNeedsTheRightToReadProductsAndATab(): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['customer.read'], 'clerk');
        $this->postJson($this->pairingsPath(), null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->client->getCookieJar()->clear();
        $this->login('clerk@twes.local', 'password-1234');
        $this->postJson($this->pairingsPath(), null, server: ['HTTP_X_TAB' => 'tab-1']);
        // The company's own answer to a member without the right: as if it were none of their business.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testClaimingIsBudgetedPerClient(): void
    {
        $this->phone();
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $this->postJson('/api/scan-pairings/claim', ['link' => str_repeat('b', 64)]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        }

        $this->postJson('/api/scan-pairings/claim', ['link' => str_repeat('b', 64)]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @return array{string, string} the pairing's id and its link */
    private function open(): array
    {
        $this->postJson($this->pairingsPath(), null, server: ['HTTP_X_TAB' => 'tab-1']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        $id = $this->stringAt($body, 'id');
        self::assertNotNull($this->em()->find(ScanPairing::class, $id));

        return [$id, $this->stringAt($body, 'link')];
    }

    private function claim(string $link): string
    {
        $this->phone();
        $this->postJson('/api/scan-pairings/claim', ['link' => $link]);
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->json(), 'key');
    }

    /** The phone: another browser, with no session cookie. */
    private function phone(): void
    {
        $this->client->getCookieJar()->clear();
    }

    private function pairingsPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/scan-pairings';
    }

    private function pairingPath(string $id): string
    {
        return $this->pairingsPath().'/'.$id;
    }
}
