<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\ApiPlatform\Payload;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ParameterNotFound;
use App\Module\Reports\Application\DeclaresReport;
use App\Module\Reports\Application\InvalidReportRequest;
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportPeriod;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** What a report's address asks: the period's two days, the comparison and the establishment, once API Platform checked their shapes. */
final readonly class ReportRequest
{
    private function __construct(
        private ?string $from,
        private ?string $to,
        public ReportComparison $comparison,
        public ?Uuid $establishment,
    ) {
    }

    public static function of(Operation $operation): self
    {
        return self::read(self::given($operation, 'from'), self::given($operation, 'to'), self::given($operation, 'compare'), self::given($operation, 'establishment'));
    }

    /** What the address gave for a parameter, null when it gave nothing. */
    private static function given(Operation $operation, string $key): mixed
    {
        $value = Paging::value($operation, $key);

        return $value instanceof ParameterNotFound ? null : $value;
    }

    /**
     * The same four values from a query string, for the report's file, which a plain controller serves: the screen hands
     * over the address it is already showing.
     *
     * @param array<array-key, mixed> $query
     */
    public static function ofQuery(array $query): self
    {
        return self::read($query['from'] ?? null, $query['to'] ?? null, $query['compare'] ?? null, $query['establishment'] ?? null);
    }

    private static function read(mixed $from, mixed $to, mixed $compare, mixed $establishment): self
    {
        if (null !== $compare && (!\is_string($compare) || null === ReportComparison::tryFrom($compare))) {
            throw new UnprocessableEntityHttpException('compare: none, previous or year.');
        }
        // Read as no establishment, a malformed one would hand over the whole company's figures under a narrower address.
        if (null !== $establishment && (!\is_string($establishment) || !Uuid::isValid($establishment))) {
            throw new UnprocessableEntityHttpException('establishment: the identifier of one of the company\'s establishments.');
        }

        return new self(
            self::day($from, 'from'),
            self::day($to, 'to'),
            null === $compare ? ReportComparison::None : ReportComparison::from($compare),
            null === $establishment ? null : Uuid::fromString($establishment),
        );
    }

    /**
     * The days the report reads: the two asked, which a report of a period requires, or today alone for a report of today,
     * which takes none, so that a period sent to it is refused rather than silently read as today.
     */
    public function periodFor(DeclaresReport $report, ReportContext $context): ReportPeriod
    {
        if (!$report->periodic()) {
            if (null !== $this->from || null !== $this->to) {
                throw new UnprocessableEntityHttpException(\sprintf('from: the report %s reads today and takes no period.', $report->key()));
            }

            return new ReportPeriod($context->today, $context->today);
        }
        if (null === $this->from || null === $this->to) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: the report %s reads a period, from one day to another.', null === $this->from ? 'from' : 'to', $report->key()));
        }
        try {
            return new ReportPeriod(new \DateTimeImmutable($this->from), new \DateTimeImmutable($this->to));
        } catch (InvalidReportRequest $refused) {
            throw new UnprocessableEntityHttpException('to: '.$refused->getMessage(), $refused);
        }
    }

    /**
     * A day the calendar has, or null when the address names none: `createFromFormat` rolls 2026-13-45 into the next
     * year rather than failing, so the day read back must be the day written.
     */
    private static function day(mixed $value, string $key): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: a calendar day, YYYY-MM-DD.', $key));
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: a calendar day, YYYY-MM-DD.', $key));
        }

        return $value;
    }
}
