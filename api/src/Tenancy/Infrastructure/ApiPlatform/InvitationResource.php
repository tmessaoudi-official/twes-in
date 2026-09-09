<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Tenancy\Application\Invitation\InvitationSummary;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An invitation as the person holding the link sees it. Both operations are reached logged out: the link
 * arrives in a mail client, and under SameSite=Strict it is opened without any session cookie.
 */
#[ApiResource(
    shortName: 'Invitation',
    operations: [
        new Get(
            uriTemplate: '/invitations/{token}',
            provider: InvitationProvider::class,
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/invitations/{token}/accept',
            processor: AcceptInvitationProcessor::class,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => ['accept']],
            read: false,
        ),
    ],
)]
final class InvitationResource
{
    public const string READ = 'invitation:read';
    public const string WRITE = 'invitation:write';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $email = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $companyName = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $roleName = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $expiresAt = null;

    #[Assert\NotBlank(groups: ['accept'])]
    #[Assert\Length(min: 1, max: 120, groups: ['accept'])]
    #[Groups([self::WRITE])]
    public string $displayName = '';

    /**
     * Twelve characters is the floor; everything else about the password is judged by the breached-password
     * check, which asks whether this exact password is already known to attackers.
     */
    #[Assert\NotBlank(groups: ['accept'])]
    #[Assert\Length(min: 12, max: 4096, groups: ['accept'])]
    #[Groups([self::WRITE])]
    public string $password = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $userId = null;

    public static function of(InvitationSummary $summary): self
    {
        $resource = new self();
        $resource->email = $summary->email;
        $resource->companyName = $summary->companyName;
        $resource->roleName = $summary->roleName;
        $resource->expiresAt = $summary->expiresAt;

        return $resource;
    }
}
