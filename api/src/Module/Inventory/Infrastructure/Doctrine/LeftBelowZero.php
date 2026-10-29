<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Whether a movement took out more than its place held: after it, every movement of its product, at its place and in its
 * lot (or with no lot, as « Sous zéro » lists a place lot by lot), summed in the order they moved (the moment, then the
 * id), comes to less than nothing. The sums are one window over the company's movements, worked out once for the whole
 * statement: a sum per movement, read again for each, took seconds at twenty thousand movements and timed out at a
 * hundred thousand on a shop whose few best sellers carry most of its history.
 *
 * The company is bound rather than read from the movement, so the database works the window out once and not per row.
 * A statement asking of a few movements (a page's) names their products too, and only their sums are worked out: a
 * product's sums are its own, so they come out the same. In DQL: `LEFT_BELOW_ZERO(m.id, :belowZeroCompany) = true`, or
 * `LEFT_BELOW_ZERO(m.id, :belowZeroCompany, :belowZeroProducts) = true`.
 */
final class LeftBelowZero extends FunctionNode
{
    private Node|string|null $movement = null;
    private ?InputParameter $company = null;
    private ?InputParameter $products = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->movement = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->company = $parser->InputParameter();
        if ($parser->getLexer()->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);
            $this->products = $parser->InputParameter();
        }
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        if (null === $this->movement || null === $this->company) {
            throw new \LogicException('LEFT_BELOW_ZERO is written before it is read.');
        }

        return \sprintf(
            '(%s IN (SELECT run.id FROM (SELECT s.id, s.quantity, SUM(s.quantity) OVER (PARTITION BY s.product_id, s.location_id, s.lot_id'
            .' ORDER BY s.at, s.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS after FROM stock_movement s WHERE s.company_id = CAST(%s AS uuid)%s) run'
            .' WHERE run.quantity < 0 AND run.after < 0))',
            \is_string($this->movement) ? $this->movement : $this->movement->dispatch($sqlWalker),
            $sqlWalker->walkInputParameter($this->company),
            null === $this->products ? '' : ' AND s.product_id IN ('.$sqlWalker->walkInputParameter($this->products).')',
        );
    }
}
