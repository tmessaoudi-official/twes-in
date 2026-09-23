<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Application;

use App\Identity\Domain\User;
use App\Scanning\Domain\ScanPairing;
use App\Scanning\Domain\ScanPairingRefused;
use App\Scanning\Domain\ScanPairingRepository;
use App\Shared\Application\RealtimePublisher;
use App\Shared\Application\RealtimeToken;
use App\Shared\Application\RealtimeTokens;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A phone lent to a computer tab as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4). The phone only ever
 * hands a code or a tapped choice to the tab, on the tab's user channel with the tab named, and the tab acts on it
 * under its own session; what comes back to the phone is what the tab chose to echo, on the pairing's own channel.
 */
final readonly class PhonePairings
{
    /** A handheld scanner's longest honest burst: a GS1 DataMatrix or a QR code with a URL. */
    private const int CODE_MAX = 512;

    public function __construct(
        private ScanPairingRepository $pairings,
        private RealtimePublisher $realtime,
        private RealtimeTokens $tokens,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** A new link for this tab; one tab lends one phone, so an earlier pairing of the tab ends. */
    public function open(Company $company, User $user, string $tab): OpenedPairing
    {
        $now = $this->clock->now();
        $ended = [];
        foreach ($this->pairings->unendedOf($user->getId()) as $earlier) {
            if ($earlier->getTab() === $tab) {
                $earlier->end($now);
                $ended[] = $earlier;
            }
        }
        $link = self::secret();
        $pairing = new ScanPairing($company, $user, $tab, $link, $now);
        $this->pairings->save($pairing, ...$ended);
        foreach ($ended as $earlier) {
            $this->tellThePhoneItEnded($earlier);
        }

        return new OpenedPairing($pairing->getId(), $link);
    }

    public function claim(string $link): ClaimedPairing
    {
        $key = self::secret();
        $pairing = $this->transactions->run(function () use ($link, $key): ScanPairing {
            $pairing = $this->pairings->lockedByLinkHash(ScanPairing::linkHash($link)) ?? throw new ScanPairingRefused('unknown');
            $pairing->claim($key, $this->clock->now());
            $this->pairings->save($pairing);

            return $pairing;
        });
        $this->toTheTab($pairing, ['event' => 'claimed']);

        return new ClaimedPairing($pairing->getId(), $key);
    }

    public function renew(Uuid $userId, Uuid $id): void
    {
        $pairing = $this->ofUser($userId, $id);
        $pairing->renew($this->clock->now());
        $this->pairings->save($pairing);
    }

    public function end(Uuid $userId, Uuid $id): void
    {
        $pairing = $this->ofUser($userId, $id);
        $pairing->end($this->clock->now());
        $this->pairings->save($pairing);
        $this->tellThePhoneItEnded($pairing);
    }

    /** Signing out: every phone lent by this person stops working at once. */
    public function endAllOf(Uuid $userId): void
    {
        $now = $this->clock->now();
        $pairings = $this->pairings->unendedOf($userId);
        foreach ($pairings as $pairing) {
            $pairing->end($now);
        }
        $this->pairings->save(...$pairings);
        foreach ($pairings as $pairing) {
            $this->tellThePhoneItEnded($pairing);
        }
    }

    public function scan(Uuid $id, string $key, string $code, string $scanId): void
    {
        if ('' === $code || \strlen($code) > self::CODE_MAX || 1 === preg_match('/[\x00-\x1c\x1e\x1f\x7f]/', $code)) {
            throw new \InvalidArgumentException(\sprintf('code: 1 to %d printable characters; the GS1 separator is the one control character a scanner types.', self::CODE_MAX));
        }
        self::uuid($scanId, 'scan');
        $this->toTheTab($this->authorised($id, $key), ['event' => 'scan', 'scan' => $scanId, 'code' => $code]);
    }

    public function choose(Uuid $id, string $key, string $echoId, string $choice): void
    {
        self::uuid($echoId, 'echo');
        if (!PairingEcho::isChoice($choice)) {
            throw new \InvalidArgumentException('choice: one of the ids the echo offered.');
        }
        $this->toTheTab($this->authorised($id, $key), ['event' => 'choice', 'echo' => $echoId, 'choice' => $choice]);
    }

    public function echo(Uuid $userId, Uuid $id, PairingEcho $echo): void
    {
        $pairing = $this->ofUser($userId, $id);
        if (!$pairing->isLive($this->clock->now())) {
            throw new ScanPairingRefused('ended');
        }
        $this->realtime->push(self::phoneChannel($pairing), ['type' => 'echo'] + $echo->toArray());
    }

    /** The phone's connection token: its own channel and nothing else. */
    public function token(Uuid $id, string $key): RealtimeToken
    {
        $channel = self::phoneChannel($this->authorised($id, $key));

        return $this->tokens->issueFor($channel, [$channel]);
    }

    private function authorised(Uuid $id, string $key): ScanPairing
    {
        $pairing = $this->pairings->get($id) ?? throw new ScanPairingRefused('unknown');
        $pairing->authorise($key, $this->clock->now());

        return $pairing;
    }

    private function ofUser(Uuid $userId, Uuid $id): ScanPairing
    {
        return $this->pairings->ofUser($userId, $id) ?? throw new ScanPairingRefused('unknown');
    }

    /** @param array<string, string> $data */
    private function toTheTab(ScanPairing $pairing, array $data): void
    {
        $this->realtime->push(
            'user:'.$pairing->getUser()->getId()->toRfc4122(),
            ['type' => 'pairing', 'event' => $data['event'], 'pairing' => $pairing->getId()->toRfc4122(), 'tab' => $pairing->getTab()] + $data,
        );
    }

    private function tellThePhoneItEnded(ScanPairing $pairing): void
    {
        $this->realtime->push(self::phoneChannel($pairing), ['type' => 'ended']);
    }

    private static function phoneChannel(ScanPairing $pairing): string
    {
        return 'scan:'.$pairing->getId()->toRfc4122();
    }

    private static function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function uuid(string $value, string $field): void
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException("$field: a UUID.");
        }
    }
}
