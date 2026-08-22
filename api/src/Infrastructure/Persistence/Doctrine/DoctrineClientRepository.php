<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Twes\Domain\Client\Client;
use Twes\Domain\Client\ClientRepository;
use Twes\Domain\Client\Contact;
use Twes\Domain\Client\PostalAddress;
use Twes\Domain\Shared\Identifier;
use Twes\Infrastructure\Tenancy\TenantContext;

/**
 * The PostgreSQL adapter for {@see ClientRepository}.
 *
 * DBAL rather than the ORM, matching {@see DoctrineInvoiceRepository} and
 * {@see DoctrineCompanySettingsRepository}. Here the invoice repository's measured reason applies directly:
 * `CLAUDE.md` § Gotchas 2026-08-06 records that a whole-rewrite of a parent plus its children through the
 * unit of work is **impossible** rather than merely slow — `remove()` on a child at a composite key plus
 * `persist()` of a new one at the SAME key raises `EntityIdentityCollisionException` from the identity map
 * before any SQL is emitted. A client's contacts are exactly that shape.
 *
 * **BOTH METHODS REFUSE OUTSIDE A TRANSACTION, AND FOR THE READ THAT IS THE POINT RATHER THAN SYMMETRY.** The
 * tenant binding row-level security compares against is written by `TenantBindingMiddleware` on
 * `beginTransaction()` and is TRANSACTION-LOCAL (`set_config(…, true)`), so outside one the connection is
 * bound to no tenant, an unbound session sees NOTHING, and a read would report "no such client" for a client
 * that exists. `CLAUDE.md` § Gotchas 2026-08-07 records that exact downgrade — a fail-closed tenancy refusal
 * turned into a silently wrong answer — costing three commits and being invisible to two reasonable fixtures.
 *
 * **THE QUERIES ARE FILTERED BY THE TENANT AS WELL AS POLICED BY IT, which is not redundant.** Row-level
 * security scopes a statement to whatever `twes.tenant_id` the CONNECTION is bound to; the explicit
 * `company_id = :tenant` scopes it to whatever tenant the APPLICATION believes it is serving. Those are two
 * different facts, and the case where they disagree is precisely the one that must not silently return
 * somebody else's client. With both, a disagreement yields zero rows rather than the wrong row.
 */
final readonly class DoctrineClientRepository implements ClientRepository
{
    private const string CLIENT = 'client';
    private const string CONTACT = 'client_contact';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {}

    public function save(Client $client): void
    {
        $tenant = $this->currentTenant('write a client');
        $this->requireTransaction('write a client');

        // THE PARENT FIRST, as an upsert. No `WHERE` predicate on the DO UPDATE, unlike the document upsert:
        // that one guards a write-once number on a legal document a client already holds, whereas a client
        // record is MEANT to be edited -- an address changes, a company is renamed -- and the only invariant is
        // one row per (tenant, id), which the primary key already enforces.
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (company_id, id, name, tax_identifier, address_line_1, address_line_2, '
                . 'address_postcode, address_city, address_country_code) '
                . 'VALUES (:tenant, :id, :name, :taxIdentifier, :line1, :line2, :postcode, :city, :country) '
                . 'ON CONFLICT (company_id, id) DO UPDATE SET '
                . 'name = EXCLUDED.name, '
                . 'tax_identifier = EXCLUDED.tax_identifier, '
                . 'address_line_1 = EXCLUDED.address_line_1, '
                . 'address_line_2 = EXCLUDED.address_line_2, '
                . 'address_postcode = EXCLUDED.address_postcode, '
                . 'address_city = EXCLUDED.address_city, '
                . 'address_country_code = EXCLUDED.address_country_code',
                self::CLIENT,
            ),
            [
                'tenant' => $tenant,
                'id' => $client->id(),
                'name' => $client->name(),
                'taxIdentifier' => $client->taxIdentifier(),
                'line1' => $client->address()?->line1,
                'line2' => $client->address()?->line2,
                'postcode' => $client->address()?->postcode,
                'city' => $client->address()?->city,
                'country' => $client->address()?->countryCode,
            ],
        );

        // THEN THE CHILDREN, DELETED AND RE-INSERTED. The aggregate is the unit of truth about its own contact
        // list, so a diff against what is stored would be a second opinion about it -- and a contact removed from
        // the aggregate has to disappear from the table, which an upsert alone cannot express. Safe inside the
        // caller's transaction: the delete and the inserts commit together or not at all.
        //
        // This is also why `save()` requires a transaction rather than opening one: an aggregate that briefly has
        // no contacts, visible to a concurrent reader, would be a lie about a state it was never in.
        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE company_id = :tenant AND client_id = :client', self::CONTACT),
            ['tenant' => $tenant, 'client' => $client->id()],
        );

        foreach ($client->contacts() as $position => $contact) {
            $this->connection->executeStatement(
                \sprintf(
                    'INSERT INTO %s (company_id, client_id, id, position, name, email, phone) '
                    . 'VALUES (:tenant, :client, :id, :position, :name, :email, :phone)',
                    self::CONTACT,
                ),
                [
                    'tenant' => $tenant,
                    'client' => $client->id(),
                    'id' => $contact->id,
                    'position' => $position,
                    'name' => $contact->name,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                ],
            );
        }
    }

    public function find(string $id): ?Client
    {
        // VALIDATED BEFORE IT REACHES A QUERY, by the type that owns the rule. An ill-formed id reaching
        // `WHERE id = :id` makes PostgreSQL raise `invalid input syntax for type uuid`, which is a 500 where the
        // caller should see a 404.
        if (!Identifier::isWellFormed($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A client id must be a canonical lowercase-hyphenated UUID, got "%s". Refused here rather than '
                . 'passed to the database, which would raise a type error and turn a missing client into a 500.',
                $id,
            ));
        }

        $tenant = $this->currentTenant('read a client');
        $this->requireTransaction('read a client');

        /**
         * @var array{
         *     name: string,
         *     tax_identifier: null|string,
         *     address_line_1: null|string,
         *     address_line_2: null|string,
         *     address_postcode: null|string,
         *     address_city: null|string,
         *     address_country_code: null|string
         * }|false $row
         */
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT name, tax_identifier, address_line_1, address_line_2, address_postcode, address_city, '
                . 'address_country_code FROM %s WHERE company_id = :tenant AND id = :id',
                self::CLIENT,
            ),
            ['tenant' => $tenant, 'id' => $id],
        );

        if (false === $row) {
            // NOT FOUND AND NOT YOURS ARE THE SAME ANSWER, deliberately. Distinguishing them would make another
            // tenant's client id answerable, which is an existence oracle over every tenant's client list.
            return null;
        }

        /** @var list<array{id: string, name: string, email: null|string, phone: null|string}> $contactRows */
        $contactRows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT id, name, email, phone FROM %s WHERE company_id = :tenant AND client_id = :client '
                . 'ORDER BY position ASC',
                self::CONTACT,
            ),
            ['tenant' => $tenant, 'client' => $id],
        );

        // ORDER BY position, NOT by insertion order or by id. The order is what a user arranged and it is
        // persisted for that reason; without the ORDER BY, PostgreSQL is free to return rows in whatever order
        // the plan produces, which changes after a VACUUM and would make a client's contact list reshuffle
        // between two page loads with no field having changed.
        return Client::fromPersistedState(
            $id,
            $row['name'],
            $row['tax_identifier'],
            self::addressFrom($row),
            array_map(
                static fn(array $contact): Contact => new Contact(
                    $contact['id'],
                    $contact['name'],
                    $contact['email'],
                    $contact['phone'],
                ),
                $contactRows,
            ),
        );
    }

    /**
     * The address, or `null` when this client has none.
     *
     * **THE THREE REQUIRED PARTS ARE CHECKED TOGETHER RATHER THAN ONE OF THEM STANDING FOR THE GROUP.** The
     * migration's `client_address_is_whole` CHECK means a half address cannot be stored, so testing
     * `address_line_1` alone would be correct today — and would silently become wrong the moment somebody
     * relaxed that constraint, in a direction nothing else would notice. Reading what the domain type actually
     * requires keeps the two statements of the rule in step.
     *
     * @param array{
     *     address_line_1: null|string,
     *     address_line_2: null|string,
     *     address_postcode: null|string,
     *     address_city: null|string,
     *     address_country_code: null|string
     * } $row
     */
    private static function addressFrom(array $row): ?PostalAddress
    {
        if (null === $row['address_line_1'] || null === $row['address_city'] || null === $row['address_country_code']) {
            return null;
        }

        return new PostalAddress(
            $row['address_line_1'],
            $row['address_line_2'],
            $row['address_postcode'],
            $row['address_city'],
            $row['address_country_code'],
        );
    }

    /**
     * @throws \RuntimeException if no tenant is bound
     */
    private function currentTenant(string $attempted): string
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to %s with no tenant bound. This is the boundary rule `CLAUDE.md` § Gotchas '
                . '2026-07-31 states: no tenant-less path may hydrate a domain aggregate. Under row-level '
                . 'security an unbound read sees nothing, which is indistinguishable from a tenant that has no '
                . 'such client — so answering it would turn a tenancy refusal into a silently wrong answer.',
                $attempted,
            ));
        }

        return $this->tenantContext->tenantId()->toString();
    }

    /**
     * @throws \RuntimeException if there is no active transaction
     */
    private function requireTransaction(string $attempted): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to %s outside a transaction. The tenant binding this table is policed by is written '
                . 'on beginTransaction() and is transaction-local, so outside one the connection is bound to no '
                . 'tenant: a read would return zero rows for a client that exists, and a write would be refused '
                . 'by the policy. The transaction is what makes the binding present rather than assumed.',
                $attempted,
            ));
        }
    }
}
