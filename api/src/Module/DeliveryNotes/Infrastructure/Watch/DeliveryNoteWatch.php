<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Watch;

use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Module\DeliveryNotes\Infrastructure\Module\DeliveryNotesModule;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The drafts waiting for whoever may validate them: a clerk without the right saves a note, and the person who holds
 * it is told here rather than by chance on the list. A draft is waiting once it holds a line (validating needs one) and
 * nobody has saved it for the parameter's hours: one still being written is not waiting yet. Oldest first; validating,
 * cancelling, deleting or emptying it takes it off by itself.
 */
final readonly class DeliveryNoteWatch implements DeclaresWatch
{
    public const string TO_VALIDATE = 'delivery_note.to_validate';

    /** What waiting means, shared by the count and the rows so the number on the home is the number of rows behind it. */
    private const string WAITING = 'dn.company_id = :company AND dn.status = :draft AND dn.updated_at < :before
        AND EXISTS (SELECT 1 FROM delivery_note_line l WHERE l.delivery_note_id = dn.id)';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        #[Autowire(param: 'app.watch.drafts_to_validate_after_hours')]
        private int $afterHours,
    ) {
    }

    public function key(): string
    {
        return 'delivery_note';
    }

    public function module(): string
    {
        return DeliveryNotesModule::KEY;
    }

    public function permission(): string
    {
        return DeliveryNotePermission::VALIDATE;
    }

    public function kinds(): array
    {
        return [self::TO_VALIDATE];
    }

    public function changedBy(): array
    {
        return ['delivery_note'];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        self::known($kind);
        [$params, $types] = $this->bindings($company, $this->clock->now());

        return Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM delivery_note dn WHERE '.self::WAITING, $params, $types));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        $now = $this->clock->now();
        [$params, $types] = $this->bindings($company, $now);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT dn.id, c.name AS customer, dn.updated_at,
                    (SELECT COUNT(*) FROM delivery_note_line l WHERE l.delivery_note_id = dn.id) AS line_count
               FROM delivery_note dn '.KeptRecords::join('customer', 'dn.customer_id', 'c', 'cn', required: true).'
              WHERE '.self::WAITING.'
              ORDER BY dn.updated_at, dn.id
              LIMIT :limit OFFSET :offset',
            $params + ['limit' => $request->size, 'offset' => $request->offset()],
            $types,
        );

        return new Page(array_map(fn (array $row): WatchItem => new WatchItem(self::TO_VALIDATE, Rows::text($row['id']), [
            'customer' => Rows::text($row['customer']),
            'lines' => Rows::int($row['line_count']),
            'hours' => $this->hoursSince(Rows::text($row['updated_at']), $now),
        ]), $rows), $total, $request);
    }

    /** Whole hours since the last save, the stored moment read on the application's clock, which wrote it. */
    private function hoursSince(string $saved, \DateTimeImmutable $now): int
    {
        $at = new \DateTimeImmutable($saved, $now->getTimezone());

        return intdiv(max(0, $now->getTimestamp() - $at->getTimestamp()), 3600);
    }

    /** @return array{array<string, mixed>, array<string, string>} */
    private function bindings(Company $company, \DateTimeImmutable $now): array
    {
        return [
            [
                'company' => $company->getId()->toRfc4122(),
                'draft' => DeliveryNoteStatus::Draft->value,
                'before' => $now->modify(\sprintf('-%d hours', $this->afterHours)),
            ],
            ['before' => Types::DATETIME_IMMUTABLE],
        ];
    }

    private static function known(string $kind): void
    {
        if (self::TO_VALIDATE !== $kind) {
            throw new \LogicException(\sprintf('The delivery notes watch has no %s.', $kind));
        }
    }
}
