<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Submissions;

use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The `submission_claim` table, a key an account sent and what it asked: claimed in a statement of its own before the
 * work starts, so a second request with the key finds it at once; marked done inside the work's own transaction
 * (`ClaimedSubmission`), so a crash between the work and its answer still says whether the work was made; answered once
 * the answer is written. Owned here through DBAL and outside Doctrine's schema, as `sessions` is.
 */
final readonly class SubmissionClaims
{
    /** How many times a key freed under this request is claimed again before giving up. */
    private const int TURNS = 3;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Claims the key for this request. A claim of the same key whose work never committed and that is older than any
     * request may run is taken back first: the request that made it stopped. A claim whose work is still running holds
     * its row until it ends (`markDone` updates it), so taking it back waits and then finds it done.
     *
     * The claim the insert loses to may be freed before it is read, by a request that made nothing (`release`): the key
     * is then free, and is claimed again. Each turn needs another request on the same key freeing its claim in between,
     * so a few turns are enough, and running out of them is an error rather than a guess.
     *
     * The company the path names is kept for its removal to take the claim with it; a path naming none that exists keeps
     * none, and the request is refused further on as it would be without a key.
     *
     * @return ?array{fingerprint: string, workDone: bool, status: ?int, headers: array<string, string>, body: ?string} null when this
     *                                                                                                                  request holds the key now, otherwise the claim another request holds
     */
    public function claim(HeldClaim $wanted, ?Uuid $company, string $fingerprint, \DateTimeImmutable $at, \DateTimeImmutable $stoppedBefore): ?array
    {
        $account = $wanted->account->toRfc4122();
        $key = $wanted->key->toRfc4122();
        $this->connection->executeStatement(
            'DELETE FROM submission_claim WHERE account_id = ? AND key = ? AND NOT work_done AND status IS NULL AND claimed_at < ?',
            [$account, $key, $stoppedBefore->format('Y-m-d H:i:s')],
        );
        for ($turn = 0; $turn < self::TURNS; ++$turn) {
            $won = $this->connection->fetchOne(
                'INSERT INTO submission_claim (account_id, key, attempt, company_id, fingerprint, claimed_at) VALUES (?, ?, ?, (SELECT id FROM company WHERE id = ?), ?, ?) ON CONFLICT (account_id, key) DO NOTHING RETURNING 1',
                [$account, $key, $wanted->attempt->toRfc4122(), $company?->toRfc4122(), $fingerprint, $at->format('Y-m-d H:i:s')],
            );
            if (false !== $won) {
                return null;
            }
            $row = $this->connection->fetchAssociative('SELECT fingerprint, work_done, status, headers, body FROM submission_claim WHERE account_id = ? AND key = ?', [$account, $key]);
            if (false !== $row) {
                return self::held($row);
            }
        }

        throw new \RuntimeException(\sprintf('The submission key was claimed and freed %d times while this request asked for it.', self::TURNS));
    }

    /** Inside the work's transaction: the claim says the work was made exactly when the work is. */
    public function markDone(HeldClaim $claim): void
    {
        $this->connection->executeStatement('UPDATE submission_claim SET work_done = true WHERE account_id = ? AND key = ? AND attempt = ?', self::named($claim));
    }

    /** @param array<string, string> $headers */
    public function answer(HeldClaim $claim, int $status, array $headers, string $body): void
    {
        $this->connection->executeStatement(
            'UPDATE submission_claim SET status = ?, headers = ?, body = ? WHERE account_id = ? AND key = ? AND attempt = ?',
            [$status, json_encode((object) $headers, \JSON_THROW_ON_ERROR), $body, ...self::named($claim)],
        );
    }

    /** A claim whose work made nothing: the key is free for the attempt that follows. */
    public function release(HeldClaim $claim): void
    {
        $this->connection->executeStatement('DELETE FROM submission_claim WHERE account_id = ? AND key = ? AND attempt = ? AND NOT work_done', self::named($claim));
    }

    /** @return int the claims made before the moment, forgotten */
    public function forgetBefore(\DateTimeImmutable $moment): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM submission_claim WHERE claimed_at < ?', [$moment->format('Y-m-d H:i:s')]);
    }

    /** @return list<string> */
    private static function named(HeldClaim $claim): array
    {
        return [$claim->account->toRfc4122(), $claim->key->toRfc4122(), $claim->attempt->toRfc4122()];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{fingerprint: string, workDone: bool, status: ?int, headers: array<string, string>, body: ?string}
     */
    private static function held(array $row): array
    {
        $read = null === $row['headers'] ? [] : json_decode(Rows::text($row['headers']), true, 4, \JSON_THROW_ON_ERROR);
        if (!\is_array($read)) {
            throw new \UnexpectedValueException('A submission\'s kept headers are not an object.');
        }
        $headers = [];
        foreach ($read as $name => $value) {
            $headers[(string) $name] = Rows::text($value);
        }

        return [
            'fingerprint' => Rows::text($row['fingerprint']),
            'workDone' => Rows::bool($row['work_done']),
            'status' => null === $row['status'] ? null : Rows::int($row['status']),
            'headers' => $headers,
            'body' => Rows::textOrNull($row['body']),
        ];
    }
}
