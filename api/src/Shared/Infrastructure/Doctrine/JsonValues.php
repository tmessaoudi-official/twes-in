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
 * JSON_VALUES(field) in DQL: the values of a JSON object column, without its keys, as text. A customer's registration
 * numbers are searched by their value (`1234567A/B/M/000`), never by the name of the identifier they are filed under.
 * The expression is IMMUTABLE, so a search index may be built on it.
 */
final class JsonValues extends FunctionNode
{
    private Node $field;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return "jsonb_path_query_array({$sqlWalker->walkStringPrimary($this->field)}, '\$.*')::text";
    }
}
