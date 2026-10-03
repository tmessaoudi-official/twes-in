<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNotePrint;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;

/**
 * How a delivery note prints today, from its customer's settings and the company's formats. Validation keeps it with
 * the note; a draft, and a note validated before it was kept, read it again on every render.
 */
final readonly class DeliveryNotePrinting
{
    public static function today(ReadSetting $settings, DeliveryNote $note): DeliveryNotePrint
    {
        $customer = $note->getCustomer();
        $context = new SettingContext($note->getCompany(), customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
        $language = $settings->value($context, 'document.language');

        return new DeliveryNotePrint(
            \is_string($language) ? $language : 'fr',
            true === $settings->value($context, 'delivery_note.show_prices'),
            true === $settings->value($context, 'delivery_note.reception_block'),
            DocumentFormats::print($settings, $context, $note->getCompany())->withoutHowToPay(),
        );
    }
}
