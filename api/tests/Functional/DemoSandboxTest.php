<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Application\BuildSandbox;
use App\Demo\Application\ClaimSandbox;
use App\Demo\Application\DemoSettings;
use App\Demo\Application\KeepSandboxPool;
use App\Demo\Application\RemoveSandboxes;
use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Demo\Infrastructure\Messenger\SandboxToBuild;
use App\Tenancy\Application\Seed\SeedPlatform;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * The demo sandboxes (docs/plans/first-client.plan.md, rulings of 2026-10-17): built ahead by the worker, one ready for
 * each country a visitor may pick, each a company of its own owned by an account of its own, taken by one visitor only.
 */
final class DemoSandboxTest extends ApiTestCase
{
    use MakesSandboxes;

    public function testThePoolQueuesOneSandboxForEachCountryAndNoMore(): void
    {
        $pool = static::getContainer()->get(KeepSandboxPool::class);

        self::assertSame(2, $pool->handle());
        self::assertSame(0, $pool->handle(), 'a sandbox waiting to be built stands for the ready one');

        self::assertSame([['FR', 'queued'], ['TN', 'queued']], $this->sandboxes());
        self::assertSame(['FR', 'TN'], $this->toBuild());
    }

    public function testNothingIsQueuedWhileTheDemoIsSwitchedOff(): void
    {
        $this->platform(DemoSettings::ENABLED, false);

        self::assertSame(0, static::getContainer()->get(KeepSandboxPool::class)->handle());
        self::assertSame([], $this->sandboxes());
    }

    public function testNoSandboxIsQueuedPastTheMostThePlatformAllows(): void
    {
        $this->platform(DemoSettings::MOST, 3);
        $this->readySandbox('TN')->claim(new \DateTimeImmutable());
        $this->readySandbox('FR')->claim(new \DateTimeImmutable());
        $this->em()->flush();

        self::assertSame(1, static::getContainer()->get(KeepSandboxPool::class)->handle(), 'the third and last place goes to the first country');
        self::assertSame(0, static::getContainer()->get(KeepSandboxPool::class)->handle());
        self::assertSame(['TN'], $this->toBuild());
    }

    public function testAClaimTakesTheReadySandboxOfItsCountryOnceAndQueuesItsReplacement(): void
    {
        $ready = $this->readySandbox('TN');
        $this->em()->flush();
        $claims = static::getContainer()->get(ClaimSandbox::class);

        $claimed = $claims->handle('TN');

        self::assertNotNull($claimed);
        self::assertTrue($claimed->companyId->equals($ready->getCompanyId() ?? self::fail('A ready sandbox has a company.')));
        self::assertTrue($claimed->ownerId->equals($ready->getOwnerId() ?? self::fail('A ready sandbox has an owner.')));
        self::assertNull($claims->handle('TN'), 'a sandbox is one visitor\'s only');
        self::assertNull($claims->handle('FR'), 'none was built for France');
        self::assertSame([['FR', 'queued'], ['TN', 'claimed'], ['TN', 'queued']], $this->sandboxes());
        self::assertSame(['FR', 'TN'], $this->toBuild());
        self::assertSame(1, $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action = 'demo.sandbox_claimed' AND entity_id = ? AND actor_user_id = ? AND company_id = ?", [$claimed->companyId->toRfc4122(), $claimed->ownerId->toRfc4122(), $claimed->companyId->toRfc4122()]), 'the claim is the first line of its visitor');
    }

    public function testAnUnknownCountryIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        static::getContainer()->get(ClaimSandbox::class)->handle('DE');
    }

    public function testABuildRunsOnceThoughItsMessageComesTwice(): void
    {
        $sandbox = new DemoSandbox(Uuid::v7(), 'TN', new \DateTimeImmutable());
        $sandbox->startBuilding(new \DateTimeImmutable());
        static::getContainer()->get(DemoSandboxRepository::class)->save($sandbox);

        static::getContainer()->get(BuildSandbox::class)->handle($sandbox->getId());

        self::assertSame([['TN', 'building']], $this->sandboxes());
        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM company', []), 'nothing was written a second time');
    }

    public function testABuildStoppedHalfwayIsTakenAwayWithItsCompanyAndOwnerAndItsCountryIsQueuedAgain(): void
    {
        $stuck = $this->openedSandbox('TN', '-2 hours');
        $recent = $this->openedSandbox('FR', '-10 minutes');
        $this->em()->flush();
        $stuckCompany = ($stuck->getCompanyId() ?? self::fail('An opened sandbox has a company.'))->toRfc4122();
        $stuckOwner = ($stuck->getOwnerId() ?? self::fail('An opened sandbox has an owner.'))->toRfc4122();

        self::assertSame(1, static::getContainer()->get(RemoveSandboxes::class)->stale());

        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM company WHERE id = ?', [$stuckCompany]));
        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM "user" WHERE id = ?', [$stuckOwner]));
        self::assertSame([['FR', 'building']], $this->sandboxes(), 'a build still within its hour is left to finish');
        self::assertSame(1, $this->numberOf("SELECT COUNT(*) FROM audit_log WHERE action = 'demo.sandbox_removed' AND entity_id = ?", [$stuckCompany]));
        self::assertSame(0, static::getContainer()->get(RemoveSandboxes::class)->stale(), 'a second run finds nothing more');
        self::assertSame(1, static::getContainer()->get(KeepSandboxPool::class)->handle());
        self::assertSame(['TN'], $this->toBuild());
        self::assertNotNull($recent->getCompanyId());
    }

    public function testABuiltSandboxIsAnActiveCompanyOwnedByAnAccountOfItsOwnAndNothingElse(): void
    {
        static::getContainer()->get(SeedPlatform::class)->converge();
        $before = $this->numberOf('SELECT COUNT(*) FROM "user"', []);
        $sandbox = new DemoSandbox(Uuid::v7(), 'TN', new \DateTimeImmutable());
        static::getContainer()->get(DemoSandboxRepository::class)->save($sandbox);
        $id = $sandbox->getId();

        static::getContainer()->get(BuildSandbox::class)->handle($id);

        self::assertLessThan(60, abs(Clock::get()->now()->getTimestamp() - time()), 'the moved clock is put back');
        $row = $this->row('SELECT state, company_id, owner_id FROM demo_sandbox WHERE id = ?', [$id->toRfc4122()]);
        self::assertSame('ready', $row['state']);
        $company = $this->row('SELECT name, status, currency FROM company WHERE id = ?', [$row['company_id']]);
        self::assertSame(['active', 'TND'], [$company['status'], $company['currency']]);
        self::assertMatchesRegularExpression('/^Carthage Conseil · démo [0-9A-F]{6}$/u', \is_string($company['name']) ? $company['name'] : '');
        self::assertSame(
            [['user_id' => $row['owner_id'], 'name' => 'owner']],
            $this->rows('SELECT m.user_id, r.name FROM membership m JOIN role r ON r.id = m.role_id WHERE m.company_id = ?', [$row['company_id']]),
            'its owner is its only member: no tester and no operator',
        );
        $owner = $this->row('SELECT email, password_hash, is_platform_operator FROM "user" WHERE id = ?', [$row['owner_id']]);
        self::assertMatchesRegularExpression('/^demo-[0-9a-f]{32}@example\.com$/', \is_string($owner['email']) ? $owner['email'] : '');
        self::assertSame(['', false], [$owner['password_hash'], $owner['is_platform_operator']], 'nobody signs in to it with a password');
        self::assertSame($before + 1, $this->numberOf('SELECT COUNT(*) FROM "user"', []), 'one account was made, its owner');
        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM membership WHERE user_id = ? AND company_id <> ?', [$row['owner_id'], $row['company_id']]));
        self::assertGreaterThan(25, $this->numberOf('SELECT COUNT(*) FROM invoice WHERE company_id = ?', [$row['company_id']]), 'the history was written');
    }

    /** @return list<string> the countries of the sandboxes queued for the worker, in order */
    private function toBuild(): array
    {
        $repository = static::getContainer()->get(DemoSandboxRepository::class);
        $countries = [];
        foreach ($this->queued() as $message) {
            if ($message instanceof SandboxToBuild) {
                $countries[] = ($repository->ofId(Uuid::fromString($message->sandboxId)) ?? self::fail('A queued sandbox exists.'))->getCountry();
            }
        }
        sort($countries);

        return $countries;
    }
}
