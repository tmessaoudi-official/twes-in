<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\DataFixtures\Scale\ScaleGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `app:database:app-role`, run as the schema's owner after every migration (infra/api/migrate): the role the application
 * connects as, with the password the environment gives it, and what it may touch in this database. Rows only: no
 * rewriting the journal, no schema, no program on the server. Granted again on every run, so a table a migration just
 * made is never left out, and a password changed in the environment is the password from the next start.
 */
#[AsCommand(name: 'app:database:app-role', description: 'Create or update the application\'s database role and grant it rows only (run as the owner, after migrating).')]
final class ApplicationRoleCommand extends Command
{
    public const string ROLE = 'twes_app';

    /** Recorded once, never rewritten: the role adds to it and reads it. */
    private const string JOURNAL = 'audit_log';

    /** The development password api/.env and compose.yaml commit, which production must not run on. */
    private const string COMMITTED = 'twes-app';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.owner_connection')] private readonly Connection $owner,
        #[Autowire(env: 'POSTGRES_APP_PASSWORD')] #[\SensitiveParameter] private readonly string $password,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Printable ASCII only: PostgreSQL normalises a password before hashing it (SASLprep), which this verifier,
        // made here, does not; the generated passwords are letters and digits.
        if (1 !== preg_match('/^[\x21-\x7e]{8,}$/', $this->password)) {
            $io->error('POSTGRES_APP_PASSWORD must be at least 8 printable ASCII characters.');

            return Command::INVALID;
        }
        if ('prod' === $this->environment && hash_equals(self::COMMITTED, $this->password)) {
            $io->error('POSTGRES_APP_PASSWORD still carries the development value committed in this repository: production will not run on it.');

            return Command::INVALID;
        }

        $role = $this->owner->quoteSingleIdentifier(self::ROLE);
        // The verifier, not the password, crosses to the server: a statement the server logs, on an error, shows no secret.
        $attributes = 'LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '.$this->owner->quote(self::scramVerifier($this->password));
        $exists = false !== $this->owner->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [self::ROLE]);
        $this->owner->executeStatement(($exists ? 'ALTER ROLE ' : 'CREATE ROLE ').$role.' '.$attributes);

        $journal = $this->owner->quoteSingleIdentifier(self::JOURNAL);
        foreach ([
            "GRANT USAGE ON SCHEMA public TO $role",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO $role",
            "REVOKE UPDATE, DELETE, TRUNCATE ON $journal FROM $role",
            "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO $role",
            "GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO $role",
        ] as $statement) {
            $this->owner->executeStatement($statement);
        }
        // The scale generator makes a schema of its own, and works only on a database named for it or for the tests
        // (ScaleGenerator::isScaleDatabase); no other database lets the role make anything, and a grant found there is
        // taken back.
        $database = (string) $this->owner->getDatabase();
        $this->owner->executeStatement(\sprintf(
            ScaleGenerator::isScaleDatabase($database) ? 'GRANT CREATE ON DATABASE %s TO %s' : 'REVOKE CREATE ON DATABASE %s FROM %s',
            $this->owner->quoteSingleIdentifier($database),
            $role,
        ));

        $io->success(\sprintf('%s %s and granted rows only in %s.', self::ROLE, $exists ? 'updated' : 'created', $database));

        return Command::SUCCESS;
    }

    /** PostgreSQL's own stored form of a SCRAM-SHA-256 password (RFC 5802, RFC 7677), as `CREATE ROLE … PASSWORD` takes it. */
    private static function scramVerifier(#[\SensitiveParameter] string $password): string
    {
        $salt = random_bytes(16);
        $iterations = 4096;
        $salted = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $stored = hash('sha256', hash_hmac('sha256', 'Client Key', $salted, true), true);
        $server = hash_hmac('sha256', 'Server Key', $salted, true);

        return \sprintf('SCRAM-SHA-256$%d:%s$%s:%s', $iterations, base64_encode($salt), base64_encode($stored), base64_encode($server));
    }
}
