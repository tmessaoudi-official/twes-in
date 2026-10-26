<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Module\Scanning\Domain\Model\ScanPairing;
use App\Module\Scanning\Domain\Model\ScanPhoto;
use App\Module\Scanning\Infrastructure\Scheduler\ClearUntakenPhotos;
use App\ModuleRegistry\Domain\Model\ModuleState;
use App\Shared\Application\RealtimePublisher;
use App\Shared\Infrastructure\Realtime\HmacJwt;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\MakesPictures;
use App\Tests\Support\RecordingRealtimePublisher;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * A phone lent to a computer tab as a scanner, over HTTP (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): the tab opens
 * a link while signed in, the phone claims it with no session at all, and from then on its key is all it presents.
 */
final class ScanPairingTest extends ApiTestCase
{
    use MakesPictures;

    private const string SCAN = '0199aaaa-0000-4000-8000-0000000000f1';

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

    /** A phone reaches the stack at an address the computer tab may not be on (docs/SPEC.md § 7, 2026-09-23 14:08). */
    public function testTheOpenedPairingNamesTheAddressAPhoneReaches(): void
    {
        $this->postJson($this->pairingsPath(), null, server: ['HTTP_X_TAB' => 'tab-1']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('https://192.0.2.10:8443', $this->json()['address'], 'api/.env.test names it, without its trailing slash');
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
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson("/api/scan-pairings/$id/scans", ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

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

    /**
     * A link left unclaimed past its window answers the tab's next word as expired, so the dialog stops saying « En
     * attente du téléphone » over a dead code; the phone that comes too late is refused and the tab hears it too; the
     * tab may still let the dead pairing go.
     */
    public function testALinkLeftUnclaimedPastItsWindowIsExpiredForTheTabAndThePhone(): void
    {
        [$id, $link] = $this->open();
        $this->em()->getConnection()->executeStatement(
            'UPDATE scan_pairing SET created_at = created_at - make_interval(secs => ?) WHERE id = ?',
            [ScanPairing::CLAIM_WITHIN_SECONDS + 1, $id],
        );

        $this->postJson($this->pairingPath($id).'/heartbeat', null);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSame(['error' => 'expired'], $this->json());

        $this->phone();
        $this->postJson('/api/scan-pairings/claim', ['link' => $link]);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSame(['error' => 'expired'], $this->json());
        self::assertSame(['type' => 'pairing', 'event' => 'expired', 'pairing' => $id, 'tab' => 'tab-1'], $this->publisher->last()['data']);

        $this->login('till@twes.local', 'password-1234');
        $this->sendJson('DELETE', $this->pairingPath($id));
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

    /** The scanner is a module a company switches off (docs/SPEC.md § 7, 2026-10-06 21:02): then no tab and no phone is answered. */
    public function testASwitchedOffScannerAnswersNeitherTheTabNorThePhone(): void
    {
        [$id, $link] = $this->open();
        [, $unclaimed] = $this->open('tab-2');
        $key = $this->claim($link);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $this->em()->persist(ModuleState::of($company, 'scanning', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $before = \count($this->publisher->pushed);

        $this->postJson("/api/scan-pairings/$id/scans", ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001'], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson("/api/scan-pairings/$id/realtime-token", null, server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson('/api/scan-pairings/claim', ['link' => $unclaimed]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertCount($before, $this->publisher->pushed);

        $this->login('till@twes.local', 'password-1234');
        $this->postJson($this->pairingsPath(), null, server: ['HTTP_X_TAB' => 'tab-1']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->pairingPath($id).'/heartbeat', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPhotoTakenWithThePhoneWaitsForTheTabWhichTakesItOnce(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $sent = self::jpeg(64, 48);

        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', $sent, parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $heard = $this->publisher->last();
        $photo = $heard['data']['photo'] ?? null;
        self::assertIsString($photo);
        self::assertSame(
            ['channel' => 'user:'.$this->userId, 'data' => ['type' => 'pairing', 'event' => 'photo', 'pairing' => $id, 'tab' => 'tab-1', 'scan' => self::SCAN, 'photo' => $photo]],
            $heard,
        );

        $this->login('till@twes.local', 'password-1234');
        $this->postJson($this->pairingPath($id)."/photos/$photo/take", null);
        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame($sent, $this->client->getResponse()->getContent(), 'the photo as the phone sent it');
        self::assertSame(0, $this->em()->getRepository(ScanPhoto::class)->count([]), 'taking it is the end of its wait');

        $this->postJson($this->pairingPath($id)."/photos/$photo/take", null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'taken once');
    }

    public function testThePhoneSendsOnlyAPictureNoLargerThanAPhotoMayBe(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $before = \count($this->publisher->pushed);

        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.gif', self::gif(), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid', $this->json()['error'] ?? null);
        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20).str_repeat("\0", 5 * 1024 * 1024), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => 'not-a-uuid'], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => str_repeat('0', 64)]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the key first');

        self::assertCount($before, $this->publisher->pushed, 'nothing reached the tab');
        self::assertSame(0, $this->em()->getRepository(ScanPhoto::class)->count([]));
    }

    /**
     * Without its key a pairing is one that never was, live or ended: the answer must not tell a guessed id from a real
     * one. Only the phone holding the key hears that the computer has let it go.
     */
    public function testAWrongKeyAnswersAsAPairingThatNeverWas(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $this->login('till@twes.local', 'password-1234');
        $this->sendJson('DELETE', $this->pairingPath($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->phone();
        $scan = ['code' => '3017620422003', 'scan' => '0199aaaa-0000-4000-8000-000000000001'];

        $this->postJson('/api/scan-pairings/0199aaaa-0000-7000-8000-00000000dead/scans', $scan, server: ['HTTP_X_PAIRING_KEY' => str_repeat('0', 64)]);
        $never = [$this->client->getResponse()->getStatusCode(), $this->json()];
        $this->postJson("/api/scan-pairings/$id/scans", $scan, server: ['HTTP_X_PAIRING_KEY' => str_repeat('0', 64)]);
        $ended = [$this->client->getResponse()->getStatusCode(), $this->json()];
        $this->postJson("/api/scan-pairings/$id/scans", $scan, server: ['HTTP_X_PAIRING_KEY' => $key]);

        self::assertSame([Response::HTTP_NOT_FOUND, ['error' => 'unknown']], $never);
        self::assertSame($never, $ended);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
    }

    /** The budget is the phone's own: whoever knows the id without the key spends a budget of their own, never its. */
    public function testRequestsWithoutTheKeyDoNotSpendThePhonesBudget(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        for ($i = 0; $i < 21; ++$i) {
            $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => str_repeat('0', 64)]);
        }
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'the wrong key has met a budget');

        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
    }

    public function testAPairingHoldsAFewPhotosAtOnce(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);

        foreach ([Response::HTTP_ACCEPTED, Response::HTTP_ACCEPTED, Response::HTTP_ACCEPTED, Response::HTTP_UNPROCESSABLE_ENTITY] as $answer) {
            $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
            self::assertResponseStatusCodeSame($answer);
        }
    }

    public function testPhotosNobodyTookInTimeNoLongerFillThePairing(): void
    {
        // Three untaken photos refused the phone until the janitor ran, up to ten minutes after their wait had ended.
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        for ($sent = 0; $sent < 3; ++$sent) {
            $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
            self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        }

        try {
            // A quarter of an hour on, the tab having kept the pairing open meanwhile, as an open tab does.
            $this->login('till@twes.local', 'password-1234');
            for ($minute = 1; $minute <= 16; ++$minute) {
                Clock::set(new MockClock(new \DateTimeImmutable("+$minute minutes")));
                $this->postJson($this->pairingPath($id).'/heartbeat', null);
                self::assertResponseIsSuccessful();
            }
            $this->phone();
            $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
            self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        } finally {
            Clock::set(new NativeClock());
        }
    }

    public function testAPhotoNobodyTookIsGoneAfterAQuarterOfAnHour(): void
    {
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $photo = $this->publisher->last()['data']['photo'] ?? null;
        self::assertIsString($photo);

        Clock::set(new MockClock(new \DateTimeImmutable('+16 minutes')));
        try {
            $this->login('till@twes.local', 'password-1234');
            $this->postJson($this->pairingPath($id)."/photos/$photo/take", null);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'too late to take');

            static::getContainer()->get(ClearUntakenPhotos::class)();
            self::assertSame(0, $this->em()->getRepository(ScanPhoto::class)->count([]), 'and cleared');
        } finally {
            Clock::set(new NativeClock());
        }
    }

    public function testOnlyWhoLentThePhoneTakesItsPhotos(): void
    {
        // Before any request: an account made afterwards meets the company as an entity the test's manager never saw.
        $this->createUser('other@twes.local', 'password-1234', $this->company, ['product.read'], 'clerk');
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
        $photo = $this->publisher->last()['data']['photo'] ?? null;
        self::assertIsString($photo);

        $this->login('other@twes.local', 'password-1234');
        $this->postJson($this->pairingPath($id)."/photos/$photo/take", null);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, $this->em()->getRepository(ScanPhoto::class)->count([]));
    }

    public function testAPhotoIsTakenOnlyUnderTheCompanyItsPairingBelongsTo(): void
    {
        // The person who lent the phone also works in another company; that company's path must not reach the photo.
        $other = $this->createCompany('Globex');
        $lender = $this->em()->getRepository(User::class)->find($this->userId);
        self::assertInstanceOf(User::class, $lender);
        $this->addMembership($lender, $other, 'member', ['product.read']);
        [$id, $link] = $this->open();
        $key = $this->claim($link);
        $this->uploadFile("/api/scan-pairings/$id/photos", 'photo.jpg', self::jpeg(20, 20), parameters: ['scan' => self::SCAN], server: ['HTTP_X_PAIRING_KEY' => $key]);
        $photo = $this->publisher->last()['data']['photo'] ?? null;
        self::assertIsString($photo);

        $this->login('till@twes.local', 'password-1234');
        $this->postJson('/api/companies/'.$other->getId()->toRfc4122()."/scan-pairings/$id/photos/$photo/take", null);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, $this->em()->getRepository(ScanPhoto::class)->count([]));
    }

    /** @return array{string, string} the pairing's id and its link */
    private function open(string $tab = 'tab-1'): array
    {
        $this->postJson($this->pairingsPath(), null, server: ['HTTP_X_TAB' => $tab]);
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
