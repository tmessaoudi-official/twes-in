<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Licensing\Domain\ValueObject\Standing;
use App\Licensing\Infrastructure\ApiPlatform\Output\SubscriptionSummary;
use App\Tenancy\Application\Approval\PlatformCompanyView;
use App\Tenancy\Application\Approval\PlatformOwnerInvitationView;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\State\Processor\DecideCompanyApprovalProcessor;
use App\Tenancy\Infrastructure\ApiPlatform\State\Provider\PlatformCompanyCollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The companies, as the platform's operators review them, a page at a time (docs/SPEC.md § 7, lists at scale):
 * searched by name or owner address, narrowed by status and country and sorted in the database; the ones waiting for
 * approval are ?status=pending, oldest first with order[createdAt]=asc, and the two decisions on one. Only an operator reaches any of it.
 */
#[ApiResource(
    shortName: 'PlatformCompany',
    operations: [
        new GetCollection(
            uriTemplate: '/platform/companies',
            name: 'platform_companies',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: PlatformCompanyCollectionProvider::class,
            security: 'is_granted("platform.company.approve")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: "Words found in a company's name or in the address of one of its owners, whatever their case and accents."),
                'status[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['pending', 'active', 'suspended', 'closed']]], description: 'Several statuses, OR\'d; a single `status=pending` still works.', constraints: []),
                'countryCode[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$']], description: 'Several countries, OR\'d, by their ISO 3166-1 alpha-2 code; a single `countryCode=TN` still works.', constraints: []),
                'order[name]' => new QueryParameter(schema: self::DIRECTION),
                'order[countryCode]' => new QueryParameter(schema: self::DIRECTION),
                'order[status]' => new QueryParameter(schema: self::DIRECTION),
                'order[createdAt]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Post(
            uriTemplate: '/platform/companies/{companyId}/approve',
            name: self::APPROVE,
            status: 200,
            input: false,
            processor: DecideCompanyApprovalProcessor::class,
            security: 'is_granted("platform.company.approve")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/platform/companies/{companyId}/reject',
            name: self::REJECT,
            status: 200,
            processor: DecideCompanyApprovalProcessor::class,
            security: 'is_granted("platform.company.approve")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::DECIDE]],
        ),
    ],
)]
final class PlatformCompanyResource
{
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];
    public const string READ = 'platform_company:read';
    public const string DECIDE = 'platform_company:decide';
    public const string APPROVE = 'platform_company_approve';
    public const string REJECT = 'platform_company_reject';

    /** Why the operator refuses or suspends it, which its owners are told; none is said when left out or blank. */
    #[ApiProperty(readable: false, schema: ['type' => ['string', 'null'], 'maxLength' => Company::SUSPENSION_REASON_MAX])]
    #[Assert\Length(max: Company::SUSPENSION_REASON_MAX)]
    #[Groups([self::DECIDE])]
    public ?string $reason = null;

    // No identifier: reached only through the uriTemplates above (see CompanyResource).
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $name = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $countryCode = '';

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['pending', 'active', 'suspended', 'closed']])]
    #[Groups([self::READ])]
    public string $status = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $createdAt = '';

    /** @var list<string> the owners' addresses */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $owners = [];

    /** @var list<array{email: string, expiresAt: string, expired: bool}> the owner invitations nobody used, open or run out lately */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['email', 'expiresAt', 'expired'], 'properties' => ['email' => ['type' => 'string'], 'expiresAt' => ['type' => 'string', 'format' => 'date-time'], 'expired' => ['type' => 'boolean']]]])]
    #[Groups([self::READ])]
    public array $ownerInvitations = [];

    /** Whether it was ever opened: a suspended company never opened was refused, and opening it approves it. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public bool $opened = true;

    /** Where the company stands in its subscription; null when licensing does not manage it. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public ?SubscriptionSummary $subscription = null;

    public static function of(PlatformCompanyView $view, ?Standing $standing = null): self
    {
        $resource = new self();
        $resource->id = $view->id;
        $resource->name = $view->name;
        $resource->countryCode = $view->countryCode;
        $resource->status = $view->status;
        $resource->createdAt = $view->createdAt;
        $resource->opened = $view->opened;
        $resource->owners = $view->owners;
        $resource->ownerInvitations = array_map(static fn (PlatformOwnerInvitationView $invitation): array => ['email' => $invitation->email, 'expiresAt' => $invitation->expiresAt, 'expired' => $invitation->expired], $view->ownerInvitations);
        $resource->subscription = null === $standing ? null : SubscriptionSummary::of($standing);

        return $resource;
    }
}
