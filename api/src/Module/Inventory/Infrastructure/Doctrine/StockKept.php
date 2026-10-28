<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Whether stock is kept of a product, as `KeepStock::tracked` says it, for a list the database counts and pages (the
 * watch's « Sous zéro », « Sorties sans stock »): a product whose stock is no longer kept cannot be counted, so a list
 * of what to count that held it would never clear. Goods only, and `article.stock_tracking` as the product says, else
 * its category, else the company. The company's value is read by the settings engine, its country's default and a
 * value it refuses included ({@see byCompany()}), and handed in; the two levels below it are read here, a stored value
 * that is no yes or no skipped as the engine skips it. `StockKeptTest` holds the two answers equal.
 *
 * It is handed the product's columns rather than reading the product itself, whose table is the Products module's:
 * the statement that calls it already reads them. In DQL:
 * `STOCK_KEPT(p.kind, IDENTITY(p.company), IDENTITY(p.category), p.id, :companyKeepsStock) = true`.
 */
final class StockKept extends FunctionNode
{
    private const string KEY = 'article.stock_tracking';

    /** @var list<Node> the product's kind, company, category and id, then the company's word */
    private array $arguments = [];

    /** What the company says, before a category or a product says otherwise. */
    public static function byCompany(ReadSetting $settings, Company $company): bool
    {
        return true === $settings->value(new SettingContext($company), 'article.stock_tracking');
    }

    /**
     * The condition, in SQL, of a product given by its columns, `$companyKeeps` being {@see byCompany()}'s answer as a
     * bound boolean.
     */
    public static function sql(string $kind, string $company, string $category, string $product, string $companyKeeps): string
    {
        $level = static fn (SettingLevel $level, string $subject): string => \sprintf(
            "(SELECT (s.value #>> '{}')::boolean FROM setting s WHERE s.level = '%s' AND s.level_id = %s::text || '/' || %s::text AND s.key = '%s' AND jsonb_typeof(s.value) = 'boolean')",
            $level->value, $company, $subject, self::KEY,
        );

        return \sprintf(
            "(%s = '%s' AND COALESCE(%s, %s, CAST(%s AS boolean)))",
            $kind, ProductKind::Goods->value, $level(SettingLevel::Product, $product), $level(SettingLevel::ProductCategory, $category), $companyKeeps,
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->arguments = [$parser->StringPrimary()];
        for ($i = 1; $i < 5; ++$i) {
            $parser->match(TokenType::T_COMMA);
            $this->arguments[] = $parser->StringPrimary();
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        [$kind, $company, $category, $product, $companyKeeps] = array_map($sqlWalker->walkStringPrimary(...), $this->arguments);

        return self::sql($kind, $company, $category, $product, $companyKeeps);
    }
}
