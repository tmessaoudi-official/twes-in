<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Identity\Application\Account\AccountView;
use App\Tenancy\Application\Company\AccountCompany;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Accounts, as the platform's operators look them up a page at a time (?q= words of an address or a name, narrowed by
 * `active` and `platformOperator`, each with the companies it belongs to) and the three things they do about one. Only an operator reaches any of it (docs/SPEC.md § 7, 2026-09-15).
 */
#[ApiResource(
    shortName: 'PlatformAccount',
    operations: [
        new GetCollection(
            uriTemplate: '/platform/accounts',
            name: 'platform_accounts',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: PlatformAccountCollectionProvider::class,
            security: 'is_granted("platform.account.manage")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in an address or a name, whatever their case and accents.'),
                'active' => new QueryParameter(schema: ['type' => 'boolean'], castToNativeType: true, description: 'Whether the account may sign in.'),
                'platformOperator' => new QueryParameter(schema: ['type' => 'boolean'], castToNativeType: true, description: 'Whether the account runs the platform.'),
                'order[email]' => new QueryParameter(schema: self::DIRECTION),
                'order[displayName]' => new QueryParameter(schema: self::DIRECTION),
                'order[createdAt]' => new QueryParameter(schema: self::DIRECTION),
                'order[active]' => new QueryParameter(schema: self::DIRECTION),
                'order[platformOperator]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Post(
            uriTemplate: '/platform/accounts/{userId}/end-sessions',
            name: self::END_SESSIONS,
            status: 200,
            input: false,
            processor: ManageAccountProcessor::class,
            security: 'is_granted("platform.account.manage")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/platform/accounts/{userId}/deactivate',
            name: self::DEACTIVATE,
            status: 200,
            input: false,
            processor: ManageAccountProcessor::class,
            security: 'is_granted("platform.account.manage")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/platform/accounts/{userId}/reactivate',
            name: self::REACTIVATE,
            status: 200,
            input: false,
            processor: ManageAccountProcessor::class,
            security: 'is_granted("platform.account.manage")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class PlatformAccountResource
{
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];
    public const string READ = 'platform_account:read';
    public const string END_SESSIONS = 'platform_account_end_sessions';
    public const string DEACTIVATE = 'platform_account_deactivate';
    public const string REACTIVATE = 'platform_account_reactivate';

    // No identifier: reached only through the uriTemplates above (see PlatformCompanyResource).
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $email = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $displayName = '';

    /** Whether the account may sign in. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $active = true;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $platformOperator = false;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $createdAt = '';

    /**
     * The companies the account belongs to with the role it holds in each, by name. Sent by the list, where a person
     * is read beside their companies, and left out of the answer to an action on one account.
     *
     * @var list<PlatformAccountCompanyRow>|null
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?array $companies = null;

    /** @param list<AccountCompany>|null $companies */
    public static function of(AccountView $view, ?array $companies = null): self
    {
        $resource = new self();
        $resource->id = $view->id;
        $resource->email = $view->email;
        $resource->displayName = $view->displayName;
        $resource->active = $view->active;
        $resource->platformOperator = $view->platformOperator;
        $resource->createdAt = $view->createdAt;
        $resource->companies = null === $companies ? null : array_map(PlatformAccountCompanyRow::of(...), $companies);

        return $resource;
    }
}
