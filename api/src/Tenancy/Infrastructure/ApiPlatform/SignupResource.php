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
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Public signup (docs/SPEC.md § 3 Auth, onboarding). Every operation is reached logged out: the first two from the
 * sign-in page, the last two from a link opened in a mail client, which under SameSite=Strict carries no session cookie.
 */
#[ApiResource(
    shortName: 'Signup',
    operations: [
        new Get(
            uriTemplate: '/signup',
            name: 'signup_availability',
            provider: SignupAvailabilityProvider::class,
            normalizationContext: ['groups' => [self::AVAILABILITY]],
        ),
        new Post(
            uriTemplate: '/signup',
            name: 'signup_request',
            processor: RequestSignupProcessor::class,
            status: 202,
            output: false,
            read: false,
            denormalizationContext: ['groups' => [self::REQUEST]],
            validationContext: ['groups' => [self::REQUEST]],
        ),
        new Get(
            uriTemplate: '/signup/{token}',
            name: 'signup_link',
            provider: SignupLinkProvider::class,
            normalizationContext: ['groups' => [self::LINK]],
        ),
        new Post(
            uriTemplate: '/signup/{token}/complete',
            name: 'signup_complete',
            processor: CompleteSignupProcessor::class,
            status: 201,
            read: false,
            normalizationContext: ['groups' => [self::COMPLETED]],
            denormalizationContext: ['groups' => [self::COMPLETE]],
            validationContext: ['groups' => [self::COMPLETE]],
        ),
    ],
)]
final class SignupResource
{
    public const string AVAILABILITY = 'signup:availability';
    public const string REQUEST = 'signup:request';
    public const string LINK = 'signup:link';
    public const string COMPLETE = 'signup:complete';
    public const string COMPLETED = 'signup:completed';

    #[ApiProperty(writable: false)]
    #[Groups([self::AVAILABILITY])]
    public bool $enabled = false;

    /** @var list<string> the countries a company may be opened in */
    #[ApiProperty(writable: false)]
    #[Groups([self::AVAILABILITY])]
    public array $countries = [];

    #[Assert\NotBlank(groups: [self::REQUEST])]
    #[Assert\Length(max: 254, groups: [self::REQUEST])]
    #[Groups([self::REQUEST, self::LINK])]
    public string $email = '';

    /** The language the mail and the account speak; French when absent or unknown. */
    #[Groups([self::REQUEST])]
    public ?string $locale = null;

    #[Assert\NotBlank(normalizer: 'trim', groups: [self::COMPLETE])]
    #[Assert\Length(max: 120, groups: [self::COMPLETE])]
    #[Groups([self::COMPLETE])]
    public string $displayName = '';

    /** Twelve characters is the floor; the breached-password check judges the rest. */
    #[Assert\NotBlank(groups: [self::COMPLETE])]
    #[Assert\Length(min: 12, max: 4096, groups: [self::COMPLETE])]
    #[Groups([self::COMPLETE])]
    public string $password = '';

    #[Assert\NotBlank(normalizer: 'trim', groups: [self::COMPLETE])]
    #[Assert\Length(max: 160, groups: [self::COMPLETE])]
    #[Groups([self::COMPLETE, self::COMPLETED])]
    public string $companyName = '';

    #[Assert\NotBlank(groups: [self::COMPLETE])]
    #[Groups([self::COMPLETE])]
    public string $countryCode = '';

    #[Assert\NotBlank(groups: [self::COMPLETE])]
    #[Groups([self::COMPLETE])]
    public string $timezone = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::COMPLETED])]
    public ?string $userId = null;

    /** pending while an operator's approval is required, active otherwise */
    #[ApiProperty(writable: false)]
    #[Groups([self::COMPLETED])]
    public ?string $companyStatus = null;
}
