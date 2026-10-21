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
 * `JSON_HAS_KEY(field, key)`: whether a JSONB object holds the key, as PHP's `isset` reads a decoded array whose blank
 * values were left out. Spelt `jsonb_exists` rather than the `?` operator, which PDO would take for a placeholder.
 */
final class JsonHasKey extends FunctionNode
{
    private Node $field;
    private Node $key;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->field = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->key = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf('jsonb_exists(%s, %s)', $sqlWalker->walkStringPrimary($this->field), $sqlWalker->walkStringPrimary($this->key));
    }
}
