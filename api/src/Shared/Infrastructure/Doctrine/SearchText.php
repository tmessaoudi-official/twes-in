<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * SEARCH_TEXT(a, b, …) in DQL: PostgreSQL's search_text(), the words of its arguments in lower case without their
 * accents (migration Version20260917120000). A list searches with the same expression its trigram index is built on,
 * `SEARCH_TEXT(columns…) LIKE CONCAT('%', SEARCH_TEXT(:words), '%')`, so "carthage" finds "Carthagé Conseil".
 */
final class SearchText extends FunctionNode
{
    /** Fewer characters than a trigram find only the row numbered so, whatever the case (docs/SPEC.md § 7). */
    public const int SHORTEST = 3;

    /** @var list<Node> */
    private array $parts = [];

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->parts[] = $parser->StringPrimary();
        while ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->parts[] = $parser->StringPrimary();
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'search_text('.implode(', ', array_map(static fn (Node $part): string => $sqlWalker->walkStringPrimary($part), $this->parts)).')';
    }

    /** Words as LIKE reads them literally: a `%` or `_` someone typed is looked for, not a wildcard. */
    public static function escapeLike(string $words): string
    {
        return addcslashes($words, '\\%_');
    }
}
