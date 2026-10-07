<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Watch;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\MemberPermission;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The invitations the worker gave up mailing (`InvitationMailFailed`) that can still be accepted: nobody received a
 * link, so whoever may invite is asked to look, until the mail goes out on a retry or the invitation is sent again
 * (docs/SPEC.md § 7, Messenger transport, row 56). A subject of the core, shown whatever is switched on. Still usable
 * is a moment, as `Invitation::isUsableAt` reads it, not the company's day: an invitation lapses at its own instant.
 */
final readonly class InvitationWatch implements DeclaresWatch
{
    public const string UNSENT = 'members.invitation_unsent';

    private const string ROWS = 'SELECT i.id, i.email, i.role_name, (r.id IS NOT NULL AND r.company_id IS NULL) AS built_in,
               i.created_at::date AS invited_on, i.expires_at::date AS expires_on, i.mail_failed_at
          FROM invitation i
          LEFT JOIN role r ON r.id = i.role_id
         WHERE i.company_id = :company AND i.mail_failed_at IS NOT NULL AND i.accepted_at IS NULL AND i.expires_at >= :now';

    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    public function key(): string
    {
        return 'members';
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return MemberPermission::WRITE;
    }

    public function kinds(): array
    {
        return [self::UNSENT];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        self::known($kind);

        return (int) self::text($this->connection->fetchOne('SELECT COUNT(*) FROM ('.self::ROWS.') watched', $this->parameters($company)));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        $rows = $this->connection->fetchAllAssociative(
            self::ROWS.' ORDER BY i.mail_failed_at, i.id LIMIT :limit OFFSET :offset',
            [...$this->parameters($company), 'limit' => $request->size, 'offset' => $request->offset()],
        );

        return new Page(array_map(static fn (array $row): WatchItem => new WatchItem(self::UNSENT, self::text($row['id']), [
            'email' => self::text($row['email']),
            'role' => self::text($row['role_name']),
            'roleBuiltIn' => true === $row['built_in'] ? 1 : 0,
            'invitedOn' => self::text($row['invited_on']),
            'expiresOn' => self::text($row['expires_on']),
        ]), $rows), $total, $request);
    }

    /** @return array{company: string, now: string} */
    private function parameters(Company $company): array
    {
        return ['company' => $company->getId()->toRfc4122(), 'now' => $this->clock->now()->format('Y-m-d H:i:s')];
    }

    private static function known(string $kind): void
    {
        if (self::UNSENT !== $kind) {
            throw new \LogicException(\sprintf('The invitation watch has no %s.', $kind));
        }
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
