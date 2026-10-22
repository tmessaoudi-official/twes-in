<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\Activity;

use App\Audit\Infrastructure\ApiPlatform\Security\AuditPermission;
use App\Identity\Application\Login\LoginAudit;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Application\Payment\ManagePayments;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\ReportColumn;
use App\Module\Reports\Application\ReportColumnKind;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportDrill;
use App\Module\Reports\Application\ReportPeriod;
use App\Module\Reports\Application\ReportRow;
use App\Shared\Domain\Service\TypedText;
use Doctrine\DBAL\Connection;

/**
 * What each person did in the company over the period, as its activity journal recorded it: how often they signed in,
 * how many actions they took and on how many days, the last of those days, the invoices and credit notes they issued and
 * the payments they recorded. Signing in and out and the account's own security (passwords, second factors, step-ups)
 * are no action in the company. Every member is a row, one who did nothing included; anyone else who acted, a former
 * member or an operator reading under the owner's support access, is a row too. It reads only what the journal still
 * keeps, and whoever reads the journal reads it: the owner and the administrator unless the company says otherwise. The
 * journal records no establishment, so the report is never narrowed to one; the days are the company's own.
 */
final readonly class ActivityByMemberReport implements DeclaresReport
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'activity-by-member';
    }

    /** It reads the journal, which every company keeps, so it is offered wherever reports are. */
    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return AuditPermission::READ;
    }

    public function byEstablishment(): bool
    {
        return false;
    }

    public function periodic(): bool
    {
        return true;
    }

    public function columns(ReportContext $context): array
    {
        return [
            new ReportColumn('member', ReportColumnKind::Label),
            new ReportColumn('sign_ins', ReportColumnKind::Count),
            new ReportColumn('actions', ReportColumnKind::Count),
            new ReportColumn('days_active', ReportColumnKind::Days),
            new ReportColumn('last_active', ReportColumnKind::Day),
            new ReportColumn('issued', ReportColumnKind::Count),
            new ReportColumn('payments', ReportColumnKind::Count),
        ];
    }

    public function rows(ReportContext $context, ReportPeriod $period): array
    {
        $zone = new \DateTimeZone($context->company->getTimezone());
        $utc = new \DateTimeZone('UTC');
        $day = '(a.at AT TIME ZONE \'UTC\' AT TIME ZONE :zone)::date';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.user_id, u.display_name, u.email,
                    COALESCE(d.sign_ins, 0) AS sign_ins, COALESCE(d.actions, 0) AS actions,
                    COALESCE(d.days_active, 0) AS days_active, d.last_active,
                    COALESCE(d.issued, 0) AS issued, COALESCE(d.payments, 0) AS payments
               FROM (SELECT m.user_id FROM membership m WHERE m.company_id = :company
                      UNION
                     SELECT a.actor_user_id FROM audit_log a
                      WHERE a.company_id = :company AND a.actor_user_id IS NOT NULL AND a.at >= :start AND a.at < :before) p
               JOIN "user" u ON u.id = p.user_id
               LEFT JOIN (
                    SELECT a.actor_user_id,
                           COUNT(*) FILTER (WHERE a.action = :login) AS sign_ins,
                           COUNT(*) FILTER (WHERE a.action NOT LIKE :auth) AS actions,
                           COUNT(DISTINCT '.$day.') FILTER (WHERE a.action NOT LIKE :auth) AS days_active,
                           MAX('.$day.') FILTER (WHERE a.action NOT LIKE :auth) AS last_active,
                           COUNT(*) FILTER (WHERE a.action = :issued) AS issued,
                           COUNT(*) FILTER (WHERE a.action = :payment) AS payments
                      FROM audit_log a
                     WHERE a.company_id = :company AND a.actor_user_id IS NOT NULL AND a.at >= :start AND a.at < :before
                     GROUP BY a.actor_user_id
               ) d ON d.actor_user_id = p.user_id
              ORDER BY COALESCE(d.actions, 0) DESC, u.display_name, u.email, p.user_id',
            [
                'company' => $context->company->getId()->toRfc4122(),
                'zone' => $context->company->getTimezone(),
                'start' => new \DateTimeImmutable($period->from->format('Y-m-d'), $zone)->setTimezone($utc)->format('Y-m-d H:i:s'),
                'before' => new \DateTimeImmutable($period->to->format('Y-m-d'), $zone)->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
                'login' => LoginAudit::LOGIN,
                'auth' => LoginAudit::PREFIX.'%',
                'issued' => InvoiceWorkflow::ISSUED,
                'payment' => ManagePayments::RECORDED,
            ],
        );
        $from = $period->from->format('Y-m-d');
        $to = $period->to->format('Y-m-d');

        return array_map(static function (array $row) use ($from, $to): ReportRow {
            $user = self::text($row['user_id']);
            $name = null === $row['display_name'] || '' === TypedText::trimmed(self::text($row['display_name'])) ? self::text($row['email']) : self::text($row['display_name']);

            return new ReportRow($user, [
                'member' => $name,
                'sign_ins' => (int) self::text($row['sign_ins']),
                'actions' => (int) self::text($row['actions']),
                'days_active' => (int) self::text($row['days_active']),
                'last_active' => null === $row['last_active'] ? null : self::text($row['last_active']),
                'issued' => (int) self::text($row['issued']),
                'payments' => (int) self::text($row['payments']),
            ], new ReportDrill('activity', ['actor' => $user, 'at.from' => $from, 'at.to' => $to]));
        }, $rows);
    }

    public function documents(ReportContext $context, ReportPeriod $period, string $rowKey): array
    {
        // Every row opens the journal on what that person did in the period.
        return [];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
