<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Demo\Infrastructure\ApiPlatform\Output\DemoMailRecord;
use App\Demo\Infrastructure\ApiPlatform\State\Provider\DemoMailsProvider;

/**
 * « Courriers de la démo »: the mails the application would have sent the sandbox's owner, kept instead, the latest
 * first. A company that is no sandbox has none to show, and answers 404 as for any page it does not have.
 */
#[ApiResource(
    shortName: 'DemoMails',
    operations: [new Get(uriTemplate: '/companies/{companyId}/demo-mails', provider: DemoMailsProvider::class, security: 'is_granted("ROLE_USER")')],
)]
final readonly class DemoMails
{
    /** @param list<DemoMailRecord> $mails */
    public function __construct(
        #[ApiProperty(required: true)] public array $mails,
    ) {
    }
}
