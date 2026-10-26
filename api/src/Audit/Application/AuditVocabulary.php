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
 * Every action the trail may record, collected once from every declaration, with the kinds of record it is recorded on.
 * Two declarations naming one action must agree on where it is recorded, or the vocabulary refuses to exist; they may
 * record it on different kinds (a password whose breach check was skipped, on an invitation or on a signup).
 */
final class AuditVocabulary
{
    /** @var array<string, AuditScope>|null */
    private ?array $scopes = null;
    /** @var array<string, list<string>> */
    private array $kinds = [];

    /** @param iterable<DeclaresAuditActions> $declarations */
    public function __construct(#[AutowireIterator('app.audit.actions')] private readonly iterable $declarations)
    {
    }

    public function scopeOf(string $action): ?AuditScope
    {
        return $this->all()[$action] ?? null;
    }

    /** @return list<string> the kinds of record the action is declared on, none when nobody declares it */
    public function kindsOf(string $action): array
    {
        $this->all();

        return $this->kinds[$action] ?? [];
    }

    /** @return array<string, AuditScope> by action, sorted */
    public function all(): array
    {
        if (null === $this->scopes) {
            $scopes = [];
            $kinds = [];
            foreach ($this->declarations as $declaration) {
                foreach ($declaration->auditActions() as $action => $recorded) {
                    if (isset($scopes[$action]) && $scopes[$action] !== $recorded->scope) {
                        throw new \LogicException(\sprintf('The audit action "%s" is declared %s by %s and %s elsewhere.', $action, $recorded->scope->value, $declaration::class, $scopes[$action]->value));
                    }
                    $scopes[$action] = $recorded->scope;
                    $kinds[$action][$recorded->kind] = $recorded->kind;
                }
            }
            ksort($scopes);
            $this->kinds = array_map(static function (array $named): array {
                sort($named);

                return $named;
            }, $kinds);
            $this->scopes = $scopes;
        }

        return $this->scopes;
    }

    /** @return list<string> every kind an action a company's journal shows is recorded on, sorted */
    public function kindsShownInAJournal(): array
    {
        $shown = [];
        foreach ($this->all() as $action => $scope) {
            if ($scope->shownInAJournal()) {
                foreach ($this->kinds[$action] as $kind) {
                    $shown[$kind] = $kind;
                }
            }
        }
        sort($shown);

        return $shown;
    }
}
