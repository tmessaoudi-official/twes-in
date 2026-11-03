<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Texts\Application\CountedForms;
use App\Texts\Domain\Service\CountedTexts;
use App\Texts\Domain\ValueObject\TextAddress;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Every text kept for a mail or a printed document its code hands a count to, at the installation's level or a
 * company's, that some count chooses no form of: « Textes » refuses such a wording when it is saved, but one saved
 * before it checked, or written by hand, makes that mail or document fail at that count alone. Named by its level,
 * language, file and key, never its words, which may be a payment instruction's bank account.
 */
final readonly class TextFormsChecks implements DeclaresDataChecks
{
    public const string FORMS = 'texts.forms';

    public function __construct(private Connection $connection, private CountedForms $forms)
    {
    }

    public function dataChecks(): array
    {
        return [self::FORMS];
    }

    public function findings(string $check): iterable
    {
        if (self::FORMS !== $check) {
            throw new \LogicException(\sprintf('No text data check is named %s.', $check));
        }
        $domains = array_keys(CountedTexts::KEYS);
        $rows = $this->connection->executeQuery(
            'SELECT company_id, language, domain, text_key, value FROM text_override WHERE domain IN (?) ORDER BY company_id NULLS FIRST, language, domain, text_key',
            [$domains],
            [ArrayParameterType::STRING],
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $address = new TextAddress(Rows::text($row['language']), Rows::text($row['domain']), Rows::text($row['text_key']));
            if (!CountedTexts::counted($address) || $this->forms->everyCountChooses(Rows::text($row['value']), $address->language)) {
                continue;
            }
            $company = Rows::textOrNull($row['company_id']);
            yield new DataFinding(
                self::FORMS,
                null === $company ? null : Uuid::fromString($company),
                \sprintf('%s text %s %s %s gives no form for some count', null === $company ? 'the installation\'s' : 'a company\'s', $address->language, $address->domain, $address->key),
            );
        }
    }
}
