<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Exception\InvalidArgumentException;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

/**
 * What every paged list's provider does between API Platform and a repository (docs/SPEC.md § 7, lists at scale): the
 * page and size API Platform's pagination read from the request, the values of the operation's `QueryParameter`s,
 * and the repository's page handed back as API Platform's paginator, which Hydra serializes with its total.
 */
final readonly class Paging
{
    public function __construct(private Pagination $pagination)
    {
    }

    /** @param array<string, mixed> $context */
    public function request(Operation $operation, array $context): PageRequest
    {
        $size = $this->pagination->getLimit($operation, $context);
        if ($size < 1) {
            throw new InvalidArgumentException('A page holds at least one row.');
        }

        return new PageRequest($this->pagination->getPage($context), $size);
    }

    /**
     * @template T
     * @template R of object
     *
     * @param Page<T>        $page
     * @param callable(T): R $resource
     *
     * @return TraversablePaginator<R>
     */
    public function paginator(Page $page, callable $resource): TraversablePaginator
    {
        return new TraversablePaginator(
            new \ArrayIterator(array_map($resource, $page->items)),
            $page->request->page,
            $page->request->size,
            $page->total,
        );
    }

    /** A declared query parameter's value once API Platform checked it, or null when the request left it out. */
    public static function value(Operation $operation, string $key): mixed
    {
        return $operation->getParameters()?->get($key)?->getValue();
    }

    /** The words of a `q` parameter, or null. */
    public static function text(Operation $operation, string $key = 'q'): ?string
    {
        $value = self::value($operation, $key);

        return \is_string($value) ? $value : null;
    }

    /**
     * The uuids a parameter named. A value that is not a uuid is left out rather than refused: a form asking about a
     * record that no longer exists should read as "not found", not as a bad request.
     *
     * @return list<Uuid>
     */
    public static function uuids(Operation $operation, string $key): array
    {
        $given = self::value($operation, $key);
        $ids = [];
        foreach (\is_array($given) ? $given : [] as $value) {
            if (\is_string($value) && Uuid::isValid($value)) {
                $ids[] = Uuid::fromString($value);
            }
        }

        return $ids;
    }

    /**
     * The `order[…]` parameters a request gave, among the sorts a list offers, in the order they are declared.
     *
     * @param list<string> $sorts
     *
     * @return array<string, 'asc'|'desc'>
     */
    public static function order(Operation $operation, array $sorts): array
    {
        $order = [];
        foreach ($sorts as $sort) {
            $direction = self::value($operation, "order[$sort]");
            if ('asc' === $direction || 'desc' === $direction) {
                $order[$sort] = $direction;
            }
        }

        return $order;
    }
}
