<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Tenancy\Application\Numbering\NumberingChanges;
use App\Tenancy\Domain\NumberFormat;
use App\Tenancy\Domain\NumberingSeries;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How each establishment of a company numbers each document type. Read with company.read, revised with
 * company.settings; every row carries the number the next document would get today, in the company's time zone.
 */
#[ApiResource(
    shortName: 'NumberingSeries',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/numbering-series',
            provider: NumberingSeriesCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/numbering-series/{seriesId}',
            processor: ReviseNumberingSeriesProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class NumberingSeriesResource
{
    public const string READ = 'numbering_series:read';
    public const string WRITE = 'numbering_series:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentId = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $establishmentCode = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $documentType = '';

    /** Text around {YYYY}, {YY}, {MM}, {EST} and exactly one {SEQ} or {SEQ:n}. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: NumberFormat::MAX_LENGTH, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $format = '';

    #[Assert\Positive(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public int $nextNumber = 1;

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['yearly', 'monthly', 'never']])]
    #[Assert\Choice(choices: ['yearly', 'monthly', 'never'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $resetPeriod = 'yearly';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $isDefault = false;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $preview = '';

    public static function of(NumberingSeries $series, \DateTimeImmutable $today): self
    {
        $resource = new self();
        $resource->id = $series->getId()->toRfc4122();
        $resource->establishmentId = $series->getEstablishment()->getId()->toRfc4122();
        $resource->establishmentCode = $series->getEstablishment()->getCode();
        $resource->documentType = $series->getDocumentType();
        $resource->format = $series->getFormat();
        $resource->nextNumber = $series->getNextNumber();
        $resource->resetPeriod = $series->getResetPeriod()->value;
        $resource->isDefault = $series->isDefault();
        $resource->preview = $series->preview($today);

        return $resource;
    }

    public function changes(): NumberingChanges
    {
        return new NumberingChanges($this->format, $this->nextNumber, $this->resetPeriod);
    }
}
