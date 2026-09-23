<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanning\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Scanning\Application\PairingEcho;
use App\Scanning\Application\PhonePairings;
use App\Scanning\Domain\ScanPairing;
use App\Scanning\Domain\ScanPairingRefused;
use App\Shared\Application\RealtimeToken;
use App\Shared\Application\RealtimeTokens;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryScanPairings;
use App\Tests\Support\RecordingRealtimePublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * The phone as a remote scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): what it may do with its key, what the
 * computer tab hears on its user's channel, and what the phone hears on its own — never more than the tab chose to echo.
 */
#[CoversClass(PhonePairings::class)]
#[CoversClass(PairingEcho::class)]
final class PhonePairingsTest extends TestCase
{
    private InMemoryScanPairings $pairings;
    private RecordingRealtimePublisher $realtime;
    private FakeTransactions $transactions;
    private MockClock $clock;
    private Company $company;
    private User $user;
    /** @var list<array{subject: string, channels: array<mixed>}> */
    private array $issued = [];

    protected function setUp(): void
    {
        $this->pairings = new InMemoryScanPairings();
        $this->realtime = new RecordingRealtimePublisher();
        $this->transactions = new FakeTransactions();
        $this->clock = new MockClock('2026-09-23 12:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->user = new User(Email::fromString('amel@twes.local'), 'Amel');
    }

    public function testOpeningGivesALinkThatIsStoredOnlyAsItsHash(): void
    {
        $opened = $this->phones()->open($this->company, $this->user, 'tab-1');

        self::assertSame(64, \strlen($opened->link));
        $stored = $this->pairings->get($opened->id);
        self::assertNotNull($stored);
        self::assertSame(ScanPairing::linkHash($opened->link), $stored->getLinkHash());
        self::assertSame('tab-1', $stored->getTab());
    }

    public function testOpeningAgainFromTheSameTabEndsTheTabsEarlierPairing(): void
    {
        $first = $this->phones()->open($this->company, $this->user, 'tab-1');
        $other = $this->phones()->open($this->company, $this->user, 'tab-2');

        $this->phones()->open($this->company, $this->user, 'tab-1');

        self::assertFalse($this->pairings->get($first->id)?->isLive($this->clock->now()));
        self::assertTrue($this->pairings->get($other->id)?->isLive($this->clock->now()));
        self::assertContains(['channel' => 'scan:'.$first->id->toRfc4122(), 'data' => ['type' => 'ended']], $this->realtime->pushed);
    }

    public function testThePhoneClaimsTheLinkOnceAndTheTabHearsIt(): void
    {
        $opened = $this->phones()->open($this->company, $this->user, 'tab-1');

        $claimed = $this->phones()->claim($opened->link);

        self::assertTrue($claimed->id->equals($opened->id));
        self::assertSame(64, \strlen($claimed->key));
        self::assertSame(1, $this->transactions->committed);
        self::assertSame(
            ['channel' => $this->userChannel(), 'data' => ['type' => 'pairing', 'event' => 'claimed', 'pairing' => $opened->id->toRfc4122(), 'tab' => 'tab-1']],
            $this->realtime->last(),
        );
        $this->refused('claimed', fn () => $this->phones()->claim($opened->link));
    }

    public function testALinkNobodyOpenedIsUnknown(): void
    {
        $this->refused('unknown', fn () => $this->phones()->claim(str_repeat('a', 64)));
    }

    public function testAScanReachesOnlyTheTabThatOpenedThePairing(): void
    {
        [$id, $key] = $this->claimedPairing();
        $scanId = Uuid::v4()->toRfc4122();

        $this->phones()->scan($id, $key, "]C10113017620422000\u{1d}10LOT-7", $scanId);

        self::assertSame(
            ['channel' => $this->userChannel(), 'data' => ['type' => 'pairing', 'event' => 'scan', 'pairing' => $id->toRfc4122(), 'tab' => 'tab-1', 'scan' => $scanId, 'code' => "]C10113017620422000\u{1d}10LOT-7"]],
            $this->realtime->last(),
        );
    }

    public function testAScanWithoutTheKeyOrAfterTheTabWentQuietReachesNobody(): void
    {
        [$id, $key] = $this->claimedPairing();
        $before = \count($this->realtime->pushed);

        $this->refused('key', fn () => $this->phones()->scan($id, 'not-the-key', '3017620422003', Uuid::v4()->toRfc4122()));
        $this->clock->sleep(ScanPairing::ALIVE_SECONDS + 1);
        $this->refused('ended', fn () => $this->phones()->scan($id, $key, '3017620422003', Uuid::v4()->toRfc4122()));
        $this->refused('unknown', fn () => $this->phones()->scan(Uuid::v7(), $key, '3017620422003', Uuid::v4()->toRfc4122()));

        self::assertCount($before, $this->realtime->pushed);
    }

    public function testACodeIsWhatAScannerTypesAndNothingElse(): void
    {
        [$id, $key] = $this->claimedPairing();

        foreach (['', str_repeat('1', 513), "301\n7620", "30\u{7}17"] as $code) {
            try {
                $this->phones()->scan($id, $key, $code, Uuid::v4()->toRfc4122());
                self::fail('accepted '.json_encode($code));
            } catch (\InvalidArgumentException) {
            }
        }
        // Without the key, even a malformed scan is refused for the key: its shape is nobody else's to learn.
        $this->refused('key', fn () => $this->phones()->scan($id, 'not-the-key', '', 'not-a-uuid'));
        $this->refused('key', fn () => $this->phones()->choose($id, 'not-the-key', 'not-a-uuid', 'Not A Choice'));
        $this->expectException(\InvalidArgumentException::class);
        $this->phones()->scan($id, $key, '3017620422003', 'not-a-uuid');
    }

    public function testAChoiceTappedOnThePhoneNamesTheEchoItAnswers(): void
    {
        [$id, $key] = $this->claimedPairing();
        $echo = Uuid::v4()->toRfc4122();

        $this->phones()->choose($id, $key, $echo, 'delivery_note');

        self::assertSame(
            ['channel' => $this->userChannel(), 'data' => ['type' => 'pairing', 'event' => 'choice', 'pairing' => $id->toRfc4122(), 'tab' => 'tab-1', 'echo' => $echo, 'choice' => 'delivery_note']],
            $this->realtime->last(),
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->phones()->choose($id, $key, $echo, 'Delete everything');
    }

    public function testTheTabEchoesTheOutcomeToThePhoneAlone(): void
    {
        [$id] = $this->claimedPairing();
        $echo = new PairingEcho(
            id: Uuid::v4()->toRfc4122(),
            scan: Uuid::v4()->toRfc4122(),
            outcome: 'done',
            message: 'scan.added',
            params: ['name' => 'Nutella 400 g', 'count' => 2],
            product: ['name' => 'Nutella 400 g', 'price' => '12.500 TND'],
            choices: [],
        );

        $this->phones()->echo($this->user->getId(), $id, $echo);

        $last = $this->realtime->last();
        self::assertSame('scan:'.$id->toRfc4122(), $last['channel']);
        self::assertSame(['type' => 'echo'] + $echo->toArray(), $last['data']);
    }

    public function testAnotherPersonCannotEchoToSomebodysPhoneNorKeepItAlive(): void
    {
        [$id] = $this->claimedPairing();
        $stranger = Uuid::v7();

        $this->refused('unknown', fn () => $this->phones()->echo($stranger, $id, $this->echo()));
        $this->refused('unknown', fn () => $this->phones()->renew($stranger, $id));
        $this->refused('unknown', fn () => $this->phones()->end($stranger, $id));
    }

    public function testAnEchoIsAShapeTheScreenCanTranslateAndNoMore(): void
    {
        $bad = [
            static fn () => new PairingEcho(Uuid::v4()->toRfc4122(), null, 'exploded', 'scan.added', [], null, []),
            static fn () => new PairingEcho(Uuid::v4()->toRfc4122(), null, 'done', 'Arbitrary sentence', [], null, []),
            static fn () => new PairingEcho(Uuid::v4()->toRfc4122(), null, 'done', 'scan.added', ['name' => str_repeat('n', 201)], null, []),
            static fn () => new PairingEcho(Uuid::v4()->toRfc4122(), null, 'done', 'scan.added', ['nested' => ['cost' => 1]], null, []),
            static fn () => new PairingEcho(Uuid::v4()->toRfc4122(), null, 'done', 'scan.added', [], ['name' => 'X', 'price' => '1', 'cost' => '0.4'], []),
            static fn () => new PairingEcho(Uuid::v4()->toRfc4122(), null, 'unclaimed', 'scan.unknown', [], null, [['id' => 'invoice', 'label' => 'scan.x', 'extra' => 1]]),
            static fn () => new PairingEcho(Uuid::v4()->toRfc4122(), null, 'unclaimed', 'scan.unknown', [], null, array_fill(0, 9, ['id' => 'invoice', 'label' => 'scan.x'])),
        ];
        foreach ($bad as $index => $make) {
            try {
                $make();
                self::fail("echo $index was accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testTheTabKeepsThePairingAliveAndEndsIt(): void
    {
        [$id, $key] = $this->claimedPairing();

        $this->clock->sleep(60);
        $this->phones()->renew($this->user->getId(), $id);
        $this->clock->sleep(60);
        $this->phones()->scan($id, $key, '3017620422003', Uuid::v4()->toRfc4122());

        $this->phones()->end($this->user->getId(), $id);

        self::assertSame(['channel' => 'scan:'.$id->toRfc4122(), 'data' => ['type' => 'ended']], $this->realtime->last());
        $this->refused('ended', fn () => $this->phones()->scan($id, $key, '3017620422003', Uuid::v4()->toRfc4122()));
    }

    public function testSigningOutEndsEveryPairingOfThatPerson(): void
    {
        [$id] = $this->claimedPairing();
        $other = $this->phones()->open($this->company, $this->user, 'tab-2');

        $this->phones()->endAllOf($this->user->getId());

        self::assertFalse($this->pairings->get($id)?->isLive($this->clock->now()));
        self::assertFalse($this->pairings->get($other->id)?->isLive($this->clock->now()));
    }

    public function testThePhonesConnectionHearsItsOwnChannelOnly(): void
    {
        [$id, $key] = $this->claimedPairing();

        $this->phones()->token($id, $key);

        self::assertSame([['subject' => 'scan:'.$id->toRfc4122(), 'channels' => ['scan:'.$id->toRfc4122()]]], $this->issued);
        $this->refused('key', fn () => $this->phones()->token($id, 'not-the-key'));
    }

    /** @return array{Uuid, string} */
    private function claimedPairing(): array
    {
        $opened = $this->phones()->open($this->company, $this->user, 'tab-1');
        $claimed = $this->phones()->claim($opened->link);

        return [$opened->id, $claimed->key];
    }

    private function echo(): PairingEcho
    {
        return new PairingEcho(Uuid::v4()->toRfc4122(), null, 'refused', 'scan.retired', [], null, []);
    }

    private function userChannel(): string
    {
        return 'user:'.$this->user->getId()->toRfc4122();
    }

    private function refused(string $reason, \Closure $act): void
    {
        try {
            $act();
        } catch (ScanPairingRefused $refused) {
            self::assertSame($reason, $refused->reason);

            return;
        }
        self::fail("expected a refusal: $reason");
    }

    private function phones(): PhonePairings
    {
        $record = function (string $subject, array $channels): void {
            $this->issued[] = ['subject' => $subject, 'channels' => $channels];
        };
        $tokens = new class($record, $this->clock) implements RealtimeTokens {
            public function __construct(private \Closure $record, private MockClock $clock)
            {
            }

            public function issue(Uuid $userId, ?Uuid $workingCompanyId): RealtimeToken
            {
                throw new \LogicException('a phone is never issued a user token');
            }

            public function issueFor(string $subject, array $channels): RealtimeToken
            {
                ($this->record)($subject, $channels);

                return new RealtimeToken('token', $this->clock->now());
            }
        };

        return new PhonePairings($this->pairings, $this->realtime, $tokens, $this->transactions, $this->clock);
    }
}
