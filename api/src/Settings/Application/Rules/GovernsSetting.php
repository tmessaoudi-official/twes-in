<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application\Rules;

use App\Shared\Domain\ValueObject\Refusal;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a setting's value alone cannot say: whether the installation may take it now, and what follows once it has.
 * The engine asks it on every way a value changes (one key, a page's save, going back to the default), so a screen
 * or a script never goes round it.
 */
#[AutoconfigureTag('app.settings.rules')]
interface GovernsSetting
{
    /** The setting it governs. */
    public function key(): string;

    /** Why the setting may not take this value now, or null. */
    public function refusal(mixed $value): ?Refusal;

    /**
     * Every refusal it can answer, with the parameters its text is given, which the screens' texts are held to.
     *
     * @return list<Refusal>
     */
    public function refusals(): array;

    /** The setting took this value, inside the transaction that stored it. */
    public function changed(mixed $value): void;
}
