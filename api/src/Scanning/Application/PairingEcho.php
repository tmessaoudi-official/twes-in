<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Application;

/**
 * What the computer tab tells the phone about one scan (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): the outcome as
 * a translation key with flat parameters, the product's name and customer price as the tab shows them, the choices
 * the tab offers, and a few short lines the tab wrote about what was read (stock, use-by date, pack contents). Held to that shape here, so the phone's channel never carries a record, only what a customer at the
 * till could read off the screen.
 */
final readonly class PairingEcho
{
    private const array OUTCOMES = ['done', 'refused', 'unclaimed'];
    private const string KEY = '/^[a-z][a-z_]*(\.[a-z][a-z_]*)+$/';
    private const string CHOICE = '/^[a-z][a-z_]{0,31}$/';
    private const int TEXT_MAX = 200;
    private const int PARAMS_MAX = 6;
    private const int CHOICES_MAX = 8;
    private const int DETAILS_MAX = 6;

    /** @var array<string, string|int> */
    public array $params;

    /** @var array{name: string, price: string}|null */
    public ?array $product;

    /** @var list<array{id: string, label: string}> */
    public array $choices;

    /** @var list<string> */
    public array $details;

    /**
     * Every array as it came, checked here rather than trusted: this is what the phone's channel will carry.
     *
     * @param array<mixed>      $params
     * @param array<mixed>|null $product
     * @param array<mixed>      $choices
     * @param array<mixed>      $details
     */
    public function __construct(
        public string $id,
        public ?string $scan,
        public string $outcome,
        public string $message,
        array $params,
        ?array $product,
        array $choices,
        array $details = [],
    ) {
        self::uuid($id, 'id');
        if (null !== $scan) {
            self::uuid($scan, 'scan');
        }
        if (!\in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('outcome: done, refused or unclaimed.');
        }
        self::key($message, 'message');
        $this->params = self::params($params);
        $this->product = null === $product ? null : self::product($product);
        $this->choices = self::choices($choices);
        $this->details = self::details($details);
    }

    /**
     * The echo as JSON brought it.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $scan = $data['scan'] ?? null;
        $outcome = $data['outcome'] ?? null;
        $message = $data['message'] ?? null;
        $params = $data['params'] ?? [];
        $product = $data['product'] ?? null;
        $choices = $data['choices'] ?? [];
        $details = $data['details'] ?? [];
        if (!\is_string($id) || !(null === $scan || \is_string($scan)) || !\is_string($outcome) || !\is_string($message)
            || !\is_array($params) || !(null === $product || \is_array($product)) || !\is_array($choices) || !\is_array($details)) {
            throw new \InvalidArgumentException('An echo is id, scan, outcome, message, params, product and choices.');
        }

        return new self($id, $scan, $outcome, $message, $params, $product, $choices, $details);
    }

    /** @return array{id: string, scan: string|null, outcome: string, message: string, params: array<string, string|int>, product: array{name: string, price: string}|null, choices: list<array{id: string, label: string}>, details: list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scan' => $this->scan,
            'outcome' => $this->outcome,
            'message' => $this->message,
            'params' => $this->params,
            'product' => $this->product,
            'choices' => $this->choices,
            'details' => $this->details,
        ];
    }

    public static function isChoice(string $choice): bool
    {
        return 1 === preg_match(self::CHOICE, $choice);
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, string|int>
     */
    private static function params(array $params): array
    {
        if (\count($params) > self::PARAMS_MAX) {
            throw new \InvalidArgumentException(\sprintf('params: at most %d.', self::PARAMS_MAX));
        }
        $checked = [];
        foreach ($params as $name => $value) {
            if (!\is_string($name) || 1 !== preg_match('/^[a-z][a-zA-Z]{0,31}$/', $name)
                || !(\is_int($value) || \is_string($value) && self::text($value))) {
                throw new \InvalidArgumentException('params: flat names with short text or whole numbers.');
            }
            $checked[$name] = $value;
        }

        return $checked;
    }

    /**
     * @param array<mixed> $product
     *
     * @return array{name: string, price: string}
     */
    private static function product(array $product): array
    {
        $name = $product['name'] ?? null;
        $price = $product['price'] ?? null;
        if (array_keys($product) !== ['name', 'price'] || !\is_string($name) || !\is_string($price) || !self::text($name) || !self::text($price)) {
            throw new \InvalidArgumentException('product: a name and a price, nothing else.');
        }

        return ['name' => $name, 'price' => $price];
    }

    /**
     * @param array<mixed> $choices
     *
     * @return list<array{id: string, label: string}>
     */
    private static function choices(array $choices): array
    {
        if (\count($choices) > self::CHOICES_MAX || !array_is_list($choices)) {
            throw new \InvalidArgumentException(\sprintf('choices: at most %d.', self::CHOICES_MAX));
        }
        $checked = [];
        foreach ($choices as $choice) {
            $id = \is_array($choice) ? ($choice['id'] ?? null) : null;
            $label = \is_array($choice) ? ($choice['label'] ?? null) : null;
            if (!\is_array($choice) || array_keys($choice) !== ['id', 'label'] || !\is_string($id) || !\is_string($label) || !self::isChoice($id)) {
                throw new \InvalidArgumentException('choices: an id and a translation key each.');
            }
            self::key($label, 'choices');
            $checked[] = ['id' => $id, 'label' => $label];
        }

        return $checked;
    }

    /**
     * @param array<mixed> $details
     *
     * @return list<string>
     */
    private static function details(array $details): array
    {
        if (\count($details) > self::DETAILS_MAX || !array_is_list($details)) {
            throw new \InvalidArgumentException(\sprintf('details: at most %d lines.', self::DETAILS_MAX));
        }
        $checked = [];
        foreach ($details as $line) {
            if (!\is_string($line) || !self::text($line)) {
                throw new \InvalidArgumentException('details: short text lines.');
            }
            $checked[] = $line;
        }

        return $checked;
    }

    private static function text(mixed $value): bool
    {
        return \is_string($value) && mb_strlen($value) <= self::TEXT_MAX;
    }

    private static function key(string $key, string $field): void
    {
        if (\strlen($key) > 80 || 1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException("$field: a translation key such as scan.added.");
        }
    }

    private static function uuid(string $value, string $field): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value)) {
            throw new \InvalidArgumentException("$field: a UUID.");
        }
    }
}
