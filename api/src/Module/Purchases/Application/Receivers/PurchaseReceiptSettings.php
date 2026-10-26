<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application\Receivers;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Shared\Domain\ValueObject\ReceiverRule;
use App\Tenancy\Domain\Model\Company;

/**
 * Whether a receipt names who of the shop received the vendor's goods: the company's to say, once for all its orders,
 * asked when the person recording wishes by default. The key and the choices are written out because the label gate
 * reads them from the source; a test keeps them equal to the constant and to `ReceiverRule`.
 */
final readonly class PurchaseReceiptSettings implements DeclaresSettings
{
    public const string RECEIVER = 'purchase_receipt.receiver';
    /** The module's key, as PurchasesModule declares it. */
    private const string MODULE = 'purchases';

    public function settings(): iterable
    {
        yield new SettingDefinition('purchase_receipt.receiver', SettingType::Enum, 'optional', SettingChain::Parties, [SettingLevel::Company], 'settings.purchase_receipt.receiver', self::MODULE, choices: ['off', 'optional', 'required']);
    }

    /** The company's rule, read once for whatever a request does with its receipts. */
    public static function receiverRule(ReadSetting $settings, Company $company): ReceiverRule
    {
        return ReceiverRule::tryFrom($settings->text(new SettingContext($company), self::RECEIVER)) ?? ReceiverRule::Optional;
    }
}
