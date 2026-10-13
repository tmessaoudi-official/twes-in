<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\Infrastructure\Scheduler\PurgeFailedSignInAddressesEveryNight;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The application only adds to the journal; two database functions, owned by the schema's owner, take addresses out
 * of it, and nothing else: one person's address once their account is deleted, and the addresses typed at a failed
 * sign-in once they are old enough. Each is proven here on the application's own role.
 */
final class JournalAddressesTest extends KernelTestCase
{
    private const string ERASED = 'erased';

    public function testOnePersonsAddressIsErasedWhereverTheJournalNamesIt(): void
    {
        $theirs = $this->entry('invitation.sent', ['email' => 'leila@example.test', 'role' => 'member']);
        $typed = $this->entry('auth.login_failed', ['email' => '  Leila@Example.TEST ', 'reason' => 'bad_credentials'], '203.0.113.7');
        $other = $this->entry('invitation.sent', ['email' => 'karim@example.test', 'role' => 'member']);

        $erased = $this->connection()->fetchOne('SELECT audit_erase_address(?)', ['leila@example.test']);

        self::assertSame(2, $erased);
        self::assertEquals(['email' => self::ERASED, 'role' => 'member'], $this->changes($theirs));
        self::assertEquals(['email' => self::ERASED, 'reason' => 'bad_credentials'], $this->changes($typed));
        self::assertEquals(['email' => 'karim@example.test', 'role' => 'member'], $this->changes($other));
        self::assertSame('invitation.sent', $this->connection()->fetchOne('SELECT action FROM audit_log WHERE id = ?', [$theirs]), 'only the address changes');
    }

    public function testAnAddressThatIsNotOneErasesNothing(): void
    {
        $this->entry('invitation.sent', ['email' => 'leila@example.test']);

        foreach (['', '%', '%@example.test', 'leila@example.test%'] as $pattern) {
            self::assertSame(0, $this->connection()->fetchOne('SELECT audit_erase_address(?)', [$pattern]), $pattern);
        }
    }

    public function testFailedSignInAddressesGoAfterTheirMonthsAndNothingElse(): void
    {
        $old = $this->entry('auth.login_failed', ['email' => 'old@example.test', 'reason' => 'bad_credentials'], '203.0.113.7', '-13 months');
        $recent = $this->entry('auth.login_failed', ['email' => 'recent@example.test', 'reason' => 'bad_credentials'], '203.0.113.8', '-11 months');
        $invitation = $this->entry('invitation.sent', ['email' => 'kept@example.test'], '203.0.113.9', '-13 months');

        $purged = $this->connection()->fetchOne('SELECT audit_purge_failed_sign_in_addresses(12)');

        self::assertGreaterThanOrEqual(1, $purged);
        self::assertEquals(['email' => self::ERASED, 'reason' => 'bad_credentials'], $this->changes($old));
        self::assertNull($this->connection()->fetchOne('SELECT ip FROM audit_log WHERE id = ?', [$old]));
        self::assertEquals(['email' => 'recent@example.test', 'reason' => 'bad_credentials'], $this->changes($recent));
        self::assertSame('203.0.113.8', $this->connection()->fetchOne('SELECT ip FROM audit_log WHERE id = ?', [$recent]));
        self::assertSame(['email' => 'kept@example.test'], $this->changes($invitation), 'only failed sign-ins are purged');
    }

    public function testTheNightlyTaskPurgesWhatIsOlderThanItsParameter(): void
    {
        $months = self::getContainer()->getParameter('app.audit.failed_sign_in_months');
        $old = $this->entry('auth.login_failed', ['email' => 'old@example.test'], '203.0.113.7', \sprintf('-%d months -1 day', $months));
        $kept = $this->entry('auth.login_failed', ['email' => 'kept@example.test'], '203.0.113.8', \sprintf('-%d months +1 day', $months));

        self::getContainer()->get(PurgeFailedSignInAddressesEveryNight::class)();

        self::assertEquals(['email' => self::ERASED], $this->changes($old));
        self::assertEquals(['email' => 'kept@example.test'], $this->changes($kept));
    }

    /** What the purge keeps is never shorter than a year, whatever it is asked. */
    public function testThePurgeRefusesFewerThanTwelveMonths(): void
    {
        $recent = $this->entry('auth.login_failed', ['email' => 'recent@example.test'], null, '-2 months');

        self::assertNotNull($this->refusal('SELECT audit_purge_failed_sign_in_addresses(1)'));
        self::assertSame(['email' => 'recent@example.test'], $this->changes($recent));
    }

    /** Owned by the schema's owner, run with its rights: reachable only by the roles granted, on a fixed search path. */
    public function testBothFunctionsAreLockedDown(): void
    {
        foreach (['audit_erase_address(text)', 'audit_purge_failed_sign_in_addresses(integer)'] as $function) {
            $row = $this->connection()->fetchAssociative(
                "SELECT p.prosecdef AS definer, array_to_string(p.proconfig, ',') AS config,
                    has_function_privilege('public', p.oid, 'EXECUTE') AS public
                 FROM pg_proc p WHERE p.oid = CAST(? AS regprocedure)",
                [$function],
            );
            self::assertSame(['definer' => true, 'config' => 'search_path=pg_catalog, pg_temp', 'public' => false], $row, $function);
        }
    }

    /** @param array<string, mixed> $changes */
    private function entry(string $action, array $changes, ?string $ip = null, string $age = 'now'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->connection()->insert('audit_log', [
            'id' => $id,
            'entity_type' => 'test',
            'action' => $action,
            'changes' => json_encode($changes, \JSON_THROW_ON_ERROR),
            'at' => new \DateTimeImmutable($age)->format('Y-m-d H:i:s'),
            'ip' => $ip,
        ]);

        return $id;
    }

    /** @return array<string, mixed> jsonb keeps its keys in an order of its own, so compare these without order */
    private function changes(string $id): array
    {
        $json = $this->connection()->fetchOne('SELECT changes FROM audit_log WHERE id = ?', [$id]);
        self::assertIsString($json);
        $changes = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($changes);
        $named = [];
        foreach ($changes as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /** Each probe in a savepoint of its own: a refused statement aborts the transaction it ran in. */
    private function refusal(string $statement): ?string
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->executeQuery($statement);

            return null;
        } catch (DbalException $refused) {
            return $refused->getMessage();
        } finally {
            $connection->rollBack();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
