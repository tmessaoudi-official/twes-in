<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Establishment;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Shared\Application\Phones\TypedPhones;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\StoredContacts;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Exception\InvalidEstablishment;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Model\NumberingSeries;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use App\Tenancy\Domain\Repository\NumberingSeriesRepository;
use App\Tenancy\Domain\ValueObject\NumberFormat;
use App\Tenancy\Domain\ValueObject\ResetPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's establishments: listed, added and revised, each change audited. A code has the shape the company's fiscal
 * preset gives it and is unique in the company; it stays revisable until a numbered document carries it. A company keeps
 * exactly one default: another establishment takes the role, the default never just steps down. A new establishment
 * numbers each document type the way the default establishment does, from one.
 */
final readonly class ManageEstablishments implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'establishment';
    public const string CREATED = 'establishment.created';
    public const string REVISED = 'establishment.revised';

    public function __construct(
        private EstablishmentRepository $establishments,
        private NumberingSeriesRepository $series,
        private FiscalPresets $presets,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private RecordVersions $versions,
        private TypedPhones $phones,
        private StoredContacts $contacts,
    ) {
    }

    /**
     * The establishment's e-mail and phone that today's rules refuse, kept from before a rule until someone changes them,
     * and a phone the platform keeps outside its country's ranges, read by its company's country as its form reads them:
     * what its page says « À vérifier ».
     *
     * @return list<'email'|'phone'|'phone_outside_ranges'>
     */
    public function contactsToCheck(Company $company, Establishment $establishment): array
    {
        return $this->contacts->toCheck($establishment->getEmail(), $establishment->getPhone(), $company->getCountryCode());
    }

    /** @return list<Establishment> the default first, then by code */
    public function list(Company $company): array
    {
        return $this->establishments->ofCompany($company->getId());
    }

    /** The regular expression, without delimiters, a code of this company matches whole. */
    public function codePattern(Company $company): string
    {
        return $this->presets->get($company->getFiscalPreset())->establishment->codePattern;
    }

    /**
     * @throws EstablishmentCodeTaken
     * @throws InvalidEstablishment
     */
    public function create(Company $company, EstablishmentDetails $details, ?Uuid $actorUserId): Establishment
    {
        return $this->transactions->run(function () use ($company, $details, $actorUserId): Establishment {
            $code = $this->checkedCode($company, $details->code);
            if (null !== $this->establishments->ofCodeInCompany($code, $company->getId())) {
                throw new EstablishmentCodeTaken(\sprintf('The company already has an establishment coded %s.', $code));
            }
            $now = $this->clock->now();
            $template = $this->defaultOf($company);
            $others = [] !== $this->establishments->ofCompany($company->getId());

            $phone = $this->keptPhone($company, $details->phone, null);
            $establishment = Establishment::create($company, $code, $details->name, false, $now);
            $establishment->revise($code, $details->name, $details->addressLine1, $details->addressLine2, $details->postalCode, $details->city, $phone, $details->email, $now);
            if ($details->isDefault) {
                $this->handOverDefault($company, $establishment, $now);
            }
            $this->establishments->save($establishment);

            // A company's numbers are unique across all its establishments: a copy that counts from 1 under a format
            // without the site code would repeat the first site's numbers, and its first issue would be refused.
            foreach ($this->seriesToCopy($company, $template) as $documentType => [$format, $reset]) {
                if ($others && !str_contains($format, '{EST}')) {
                    $format = '{EST}-'.$format;
                }
                $this->series->save(NumberingSeries::create($company, $establishment, $documentType, new NumberFormat($format), $reset, true, $now));
            }
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $establishment->getId(), self::CREATED, $actorUserId, ['code' => $establishment->getCode(), 'name' => $establishment->getName(), 'is_default' => $establishment->isDefault()], $company->getId()));

            return $establishment;
        });
    }

    /**
     * @throws EstablishmentNotFound  for an establishment that does not exist or belongs to another company
     * @throws EstablishmentCodeTaken
     * @throws InvalidEstablishment
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $establishmentId, EstablishmentDetails $details, ?Uuid $actorUserId, ?int $version = null): Establishment
    {
        return $this->transactions->run(function () use ($company, $establishmentId, $details, $actorUserId, $version): Establishment {
            $establishment = $this->establishments->ofIdInCompany($establishmentId, $company->getId()) ?? throw new EstablishmentNotFound('No such establishment.');
            if (null !== $version) {
                $this->versions->expect($establishment, $version);
            }
            $code = $this->checkedCode($company, $details->code);
            $holder = $this->establishments->ofCodeInCompany($code, $company->getId());
            if (null !== $holder && !$holder->getId()->equals($establishment->getId())) {
                throw new EstablishmentCodeTaken(\sprintf('The company already has an establishment coded %s.', $code));
            }
            if ($code !== $establishment->getCode() && $this->isCodeLocked($company, $establishment)) {
                throw new InvalidEstablishment('code', \sprintf('Numbered documents carry the code %s: it no longer changes.', $establishment->getCode()), 'code_kept');
            }
            if ($establishment->isDefault() && !$details->isDefault) {
                throw new InvalidEstablishment('isDefault', 'A company keeps one default establishment: make another one the default instead.', 'default_kept');
            }

            $phone = $this->keptPhone($company, $details->phone, $establishment->getPhone());
            self::checkEmail($details->email, $establishment->getEmail());
            $now = $this->clock->now();
            $before = self::fields($establishment);
            $establishment->revise($code, $details->name, $details->addressLine1, $details->addressLine2, $details->postalCode, $details->city, $phone, $details->email, $now);
            if ($details->isDefault && !$establishment->isDefault()) {
                $this->handOverDefault($company, $establishment, $now);
            }
            $after = self::fields($establishment);
            $changed = array_keys(array_filter($after, static fn (mixed $value, string $field): bool => $value !== $before[$field], \ARRAY_FILTER_USE_BOTH));

            if ([] !== $changed) {
                $this->establishments->save($establishment);
                $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $establishment->getId(), self::REVISED, $actorUserId, ['fields' => $changed], $company->getId()));
            }

            return $establishment;
        });
    }

    /** Whether a document carries the establishment's code: once one of its series has numbered a document. */
    public function isCodeLocked(Company $company, Establishment $establishment): bool
    {
        foreach ($this->series->ofCompany($company->getId()) as $series) {
            if ($series->isNumbered() && $series->getEstablishment()->getId()->equals($establishment->getId())) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address is held to its rule once it changes (row 208): the form leaves a revision's address to this, so one kept
     * before today's rule never refuses another change.
     *
     * @throws InvalidEstablishment
     */
    private static function checkEmail(?string $typed, ?string $held): void
    {
        $typed = null === $typed ? null : TypedText::trimmed($typed);
        $refusal = null === $typed || '' === $typed || $typed === $held ? null : ContactRules::email($typed);
        if (null !== $refusal) {
            throw new InvalidEstablishment('email', $refusal->message, $refusal->reason, $refusal->params);
        }
    }

    /**
     * The phone as kept (`TypedPhones`), read by the company's country, judged only once it changes. An establishment has
     * no « À vérifier »: a number outside the ones handed out is kept.
     *
     * @throws InvalidEstablishment
     */
    private function keptPhone(Company $company, ?string $typed, ?string $held): ?string
    {
        $kept = $this->phones->kept($typed, $held, $company->getCountryCode());
        if (null !== $kept->refusal) {
            throw new InvalidEstablishment('phone', $kept->refusal->message, $kept->refusal->reason, $kept->refusal->params);
        }

        return $kept->phone;
    }

    private function checkedCode(Company $company, string $code): string
    {
        $code = TypedText::trimmed($code);
        $preset = $this->presets->get($company->getFiscalPreset())->establishment;
        if (1 !== preg_match('#'.str_replace('#', '\#', $preset->codePattern).'#u', $code)) {
            // The preset's own first code shows the shape: a pattern means nothing to the person typing.
            throw new InvalidEstablishment('code', \sprintf('"%s" does not have the shape the %s preset gives an establishment code.', $code, $company->getFiscalPreset()), 'establishment_code', ['example' => $preset->defaultCode]);
        }

        return $code;
    }

    private function defaultOf(Company $company): ?Establishment
    {
        return array_find($this->establishments->ofCompany($company->getId()), static fn (Establishment $e): bool => $e->isDefault());
    }

    /** The predecessor steps down first, and is written first: the database holds one default per company at every moment. */
    private function handOverDefault(Company $company, Establishment $successor, \DateTimeImmutable $now): void
    {
        $predecessor = $this->defaultOf($company);
        if (null !== $predecessor && $predecessor !== $successor) {
            $predecessor->markDefault(false, $now);
            $this->establishments->save($predecessor);
        }
        $successor->markDefault(true, $now);
    }

    /** @return array<string, array{string, ResetPeriod}> by document type: the default establishment's series, else the preset's */
    private function seriesToCopy(Company $company, ?Establishment $template): array
    {
        $copy = [];
        foreach ($this->series->ofCompany($company->getId()) as $series) {
            if (null !== $template && $series->isDefault() && $series->getEstablishment()->getId()->equals($template->getId())) {
                $copy[$series->getDocumentType()] = [$series->getFormat(), $series->getResetPeriod()];
            }
        }
        if ([] === $copy) {
            foreach ($this->presets->get($company->getFiscalPreset())->numbering as $documentType => $numbering) {
                $copy[$documentType] = [$numbering->format, ResetPeriod::from($numbering->reset)];
            }
        }

        return $copy;
    }

    /** @return array<string, string|bool|null> */
    private static function fields(Establishment $e): array
    {
        return [
            'code' => $e->getCode(),
            'name' => $e->getName(),
            'addressLine1' => $e->getAddressLine1(),
            'addressLine2' => $e->getAddressLine2(),
            'postalCode' => $e->getPostalCode(),
            'city' => $e->getCity(),
            'phone' => $e->getPhone(),
            'email' => $e->getEmail(),
            'isDefault' => $e->isDefault(),
        ];
    }

    public function auditActions(): array
    {
        return [
            self::CREATED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::REVISED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
