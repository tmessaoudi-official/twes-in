<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * G1a: users, companies, roles, memberships, the audit log, and the sessions table the PDO session handler
 * owns (its DDL is Symfony's own for PostgreSQL; Doctrine's schema_filter keeps it out of every later diff).
 */
final class Version20260909000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G1a: user, company, role, membership, audit_log, sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(120) NOT NULL, locale VARCHAR(5) NOT NULL, is_active BOOLEAN NOT NULL, is_platform_operator BOOLEAN NOT NULL, last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failed_login_count INT NOT NULL, locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, password_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, security_stamp VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, company_id UUID DEFAULT NULL, entity_type VARCHAR(64) NOT NULL, entity_id UUID DEFAULT NULL, action VARCHAR(64) NOT NULL, actor_user_id UUID DEFAULT NULL, changes JSONB NOT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ip VARCHAR(45) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_log_entity ON audit_log (entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_log_at ON audit_log (at)');
        $this->addSql('CREATE TABLE company (id UUID NOT NULL, name VARCHAR(160) NOT NULL, country_code VARCHAR(2) NOT NULL, currency VARCHAR(3) NOT NULL, locale VARCHAR(5) NOT NULL, timezone VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE membership (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, company_id UUID NOT NULL, role_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_membership_user_company ON membership (user_id, company_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285A76ED395 ON membership (user_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285979B1AD6 ON membership (company_id)');
        $this->addSql('CREATE INDEX IDX_86FFD285D60322AC ON membership (role_id)');
        $this->addSql('CREATE TABLE role (id UUID NOT NULL, name VARCHAR(64) NOT NULL, permissions JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_role_company_name ON role (company_id, name)');
        $this->addSql('CREATE INDEX IDX_57698A6A979B1AD6 ON role (company_id)');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE membership ADD CONSTRAINT FK_86FFD285D60322AC FOREIGN KEY (role_id) REFERENCES role (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE role ADD CONSTRAINT FK_57698A6A979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler::createTable(), pgsql branch.
        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL PRIMARY KEY, sess_data BYTEA NOT NULL, sess_lifetime INTEGER NOT NULL, sess_time INTEGER NOT NULL)');
        $this->addSql('CREATE INDEX sessions_sess_lifetime_idx ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD285A76ED395');
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD285979B1AD6');
        $this->addSql('ALTER TABLE membership DROP CONSTRAINT FK_86FFD285D60322AC');
        $this->addSql('ALTER TABLE role DROP CONSTRAINT FK_57698A6A979B1AD6');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE company');
        $this->addSql('DROP TABLE membership');
        $this->addSql('DROP TABLE role');
    }
}
