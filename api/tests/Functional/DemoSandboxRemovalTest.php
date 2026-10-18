<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Application\RemoveSandboxes;
use App\Demo\Infrastructure\Scheduler\TendDemoSandboxesEveryFiveMinutes;
use App\Identity\Infrastructure\Session\PostgresSessionHandler;

/**
 * A sandbox is taken away once its visitor is done with it: signed out, or away longer than a demo session stays
 * signed in (two hours, where a real account's lasts thirty minutes), so the visitor can step away and find everything
 * as left. The worker's tend finds them; a run that finds nothing more takes nothing.
 */
final class DemoSandboxRemovalTest extends ApiTestCase
{
    use MakesSandboxes;

    protected function setUp(): void
    {
        parent::setUp();
        // Its own client address, so no other test's entries count toward this one's budget.
        $this->client->setServerParameters(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);
    }

    public function testADemoSessionStaysSignedInForTheDemosOwnIdleLimit(): void
    {
        $this->entered();

        self::assertSame(
            static::getContainer()->getParameter('app.demo.idle_ttl'),
            $this->client->getRequest()->getSession()->get(PostgresSessionHandler::IDLE_LIMIT),
        );
        self::assertSame(7200, static::getContainer()->getParameter('app.demo.idle_ttl'));
    }

    public function testASandboxInUseIsKeptAndIsTakenAwayOnceItsVisitorSignsOut(): void
    {
        $company = $this->entered();
        self::assertSame(0, $this->removals()->doneWith(), 'the visitor is still there');

        $this->postJson('/api/auth/logout', []);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->removals()->doneWith());
        self::assertSame(0, $this->numberOf('SELECT count(*) FROM company WHERE id = ?', [$company]), 'its company goes with it');
        self::assertSame(0, $this->numberOf('SELECT count(*) FROM demo_sandbox WHERE company_id = ?', [$company]));
        self::assertSame(0, $this->removals()->doneWith(), 'a second run finds nothing more');
    }

    public function testTheWorkersTendTakesAwayASandboxItsVisitorSignedOutOf(): void
    {
        $company = $this->entered();
        $this->postJson('/api/auth/logout', []);
        self::assertResponseIsSuccessful();

        (static::getContainer()->get(TendDemoSandboxesEveryFiveMinutes::class))();

        self::assertSame(0, $this->numberOf('SELECT count(*) FROM company WHERE id = ?', [$company]));
    }

    public function testASandboxLeftLongerThanTheDemoStaysSignedInIsTakenAway(): void
    {
        $company = $this->entered();
        $this->lastSeen($company, 'PT1H50M');
        self::assertSame(0, $this->removals()->doneWith(), 'within the two hours, it waits for its visitor');

        $this->lastSeen($company, 'PT2H10M');

        self::assertSame(1, $this->removals()->doneWith());
        self::assertSame(0, $this->numberOf('SELECT count(*) FROM company WHERE id = ?', [$company]));
    }

    public function testAClaimNeverUsedAfterItIsTakenAwayOnceTheLimitHasPassed(): void
    {
        $this->readySandbox('TN');
        $this->em()->flush();
        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->removals()->doneWith(), 'no request after the claim yet, but the claim is recent');

        $this->em()->getConnection()->executeStatement("UPDATE demo_sandbox SET claimed_at = now() - interval '3 hours' WHERE state = 'claimed'");

        self::assertSame(1, $this->removals()->doneWith());
    }

    public function testOnlyClaimedSandboxesAreEverDoneWith(): void
    {
        $this->readySandbox('TN');
        $this->openedSandbox('FR', '-3 hours');
        $this->em()->flush();

        self::assertSame(0, $this->removals()->doneWith());
        self::assertSame([['FR', 'building'], ['TN', 'ready']], $this->sandboxes());
    }

    /** A ready Tunisian sandbox claimed by a visitor, who then asks one page: the company's id. */
    private function entered(): string
    {
        $this->readySandbox('TN');
        $this->em()->flush();
        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        self::assertResponseIsSuccessful();
        $company = $this->json()['company'] ?? null;
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();

        return \is_array($company) && \is_string($company['id'] ?? null) ? $company['id'] : self::fail('The visitor works in the sandbox.');
    }

    /** Moves the visitor's last request that long into the past, as the record of the session keeps it. */
    private function lastSeen(string $company, string $ago): void
    {
        $moved = $this->em()->getConnection()->executeStatement(
            'UPDATE user_session SET last_seen_at = ?, created_at = ? WHERE user_id = (SELECT owner_id FROM demo_sandbox WHERE company_id = ?)',
            [$at = new \DateTimeImmutable()->sub(new \DateInterval($ago)), $at, $company],
            [0 => 'datetime_immutable', 1 => 'datetime_immutable'],
        );
        self::assertSame(1, $moved, 'the visitor\'s one session');
        $this->em()->getConnection()->executeStatement('UPDATE demo_sandbox SET claimed_at = ? WHERE company_id = ?', [$at, $company], [0 => 'datetime_immutable']);
    }

    private function removals(): RemoveSandboxes
    {
        return static::getContainer()->get(RemoveSandboxes::class);
    }
}
