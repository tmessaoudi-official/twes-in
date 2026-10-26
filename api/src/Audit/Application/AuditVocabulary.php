<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Domain\ValueObject\AuditScope;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every action the trail may record, collected once from every declaration. Two declarations naming one action must
 * agree on where it is recorded, or the vocabulary refuses to exist.
 */
final class AuditVocabulary
{
    /** @var array<string, AuditScope>|null */
    private ?array $actions = null;

    /** @param iterable<DeclaresAuditActions> $declarations */
    public function __construct(#[AutowireIterator('app.audit.actions')] private readonly iterable $declarations)
    {
    }

    public function scopeOf(string $action): ?AuditScope
    {
        return $this->all()[$action] ?? null;
    }

    /** @return array<string, AuditScope> by action, sorted */
    public function all(): array
    {
        if (null === $this->actions) {
            $actions = [];
            foreach ($this->declarations as $declaration) {
                foreach ($declaration->auditActions() as $action => $scope) {
                    if (isset($actions[$action]) && $actions[$action] !== $scope) {
                        throw new \LogicException(\sprintf('The audit action "%s" is declared %s by %s and %s elsewhere.', $action, $scope->value, $declaration::class, $actions[$action]->value));
                    }
                    $actions[$action] = $scope;
                }
            }
            ksort($actions);
            $this->actions = $actions;
        }

        return $this->actions;
    }
}
