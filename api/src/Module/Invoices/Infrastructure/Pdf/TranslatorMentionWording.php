<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Module\Invoices\Application\MentionWording;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;

/** A mention's wording as the template prints it: `translations/fiscal.<language>.yaml`, read from its catalogue. */
final readonly class TranslatorMentionWording implements MentionWording
{
    public function __construct(#[Autowire(service: 'translator')] private TranslatorBagInterface $translator)
    {
    }

    public function placeholders(string $key, string $language): array
    {
        preg_match_all('/%([a-z_]+)%/', $this->translator->getCatalogue($language)->get($key, 'fiscal'), $found);

        return array_values(array_unique($found[1]));
    }
}
