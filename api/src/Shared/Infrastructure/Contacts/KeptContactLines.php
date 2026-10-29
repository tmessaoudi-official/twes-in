<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Contacts;

use App\Shared\Domain\ValueObject\Refusal;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The « À vérifier » line the night leaves on a customer or a vendor keeping an e-mail or a phone today's rule refuses
 * (row 208), in its company's language, since no one is at a screen: the field, the value as it is kept and why the
 * rule refuses it, worded as an import's set-aside cell is, with the country's example for a phone of the wrong length.
 * Read by the company itself, it names the value, which the operators' data check never does.
 */
final readonly class KeptContactLines
{
    private const string DOMAIN = 'imports';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param 'email'|'phone' $field
     * @param bool            $ofContact whether the value is a contact's, named by `$contactName` when it has one
     */
    public function line(string $field, string $value, Refusal $refusal, string $locale, bool $ofContact = false, ?string $contactName = null): string
    {
        $why = $this->translator->trans('set_aside.why.'.$refusal->reason, [], self::DOMAIN, $locale);
        $example = $refusal->params['example'] ?? null;
        if (\is_string($example)) {
            $why = $this->translator->trans('set_aside.with_example', ['%why%' => $why, '%example%' => $example], self::DOMAIN, $locale);
        }
        $parameters = ['%label%' => $this->translator->trans('column.'.$field, [], self::DOMAIN, $locale), '%value%' => $value, '%why%' => $why];

        return match (true) {
            !$ofContact => $this->translator->trans('kept.line', $parameters, self::DOMAIN, $locale),
            null === $contactName => $this->translator->trans('kept.unnamed_contact', $parameters, self::DOMAIN, $locale),
            default => $this->translator->trans('kept.contact', [...$parameters, '%name%' => $contactName], self::DOMAIN, $locale),
        };
    }
}
