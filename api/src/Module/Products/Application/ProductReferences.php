<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Module\Products\Domain\InvalidProduct;
use App\Module\Products\Domain\ProductCategory;
use App\Module\Products\Domain\ProductCategoryRepository;
use App\Module\Products\Domain\ProductReferenceSequenceRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ReferenceFormat;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The reference a product left without one is given (docs/SPEC.md § 7, 2026-09-17 (3)): the format its category or
 * company sets, with the company's next number that no product already holds. The form shows it before saving
 * without taking it; the save takes the next free one, so another save in between only moves it on.
 */
final readonly class ProductReferences
{
    public function __construct(
        private ProductReferenceSequenceRepository $sequences,
        private ProductRepository $products,
        private ProductCategoryRepository $categories,
        private ReadSetting $settings,
        private Transactions $transactions,
    ) {
    }

    /**
     * What a product saved now in that category would be given, nothing taken.
     *
     * @throws InvalidProduct when the category is not the company's
     */
    public function preview(Company $company, ?Uuid $categoryId): string
    {
        $category = null === $categoryId ? null : ($this->categories->ofIdInCompany($categoryId, $company->getId())
            ?? throw new InvalidProduct('categoryId', 'The category is not one of the company\'s.'));
        $format = $this->format($company, $category);
        $number = $this->sequences->of($company->getId())?->nextNumber() ?? 1;
        do {
            $reference = $format->render($number++);
        } while ($this->held($company, $reference));

        return $reference;
    }

    /**
     * The next free reference, taken: inside the transaction that stores its product, which holds the company's
     * counter until it ends, so two saves never take one reference.
     *
     * @throws InvalidProduct when the company's format no longer gives a reference
     */
    public function take(Company $company, ?ProductCategory $category): string
    {
        if (!$this->transactions->active()) {
            throw new \LogicException('A reference is taken inside the transaction that stores its product.');
        }
        $format = $this->format($company, $category);
        $sequence = $this->sequences->lockedFor($company);
        // A number a product already holds as typed or imported is passed over for good, not tried again next time.
        do {
            $reference = $format->render($sequence->take());
        } while ($this->held($company, $reference));
        $this->sequences->save($sequence);

        return $reference;
    }

    /** @throws InvalidProduct */
    private function format(Company $company, ?ProductCategory $category): ReferenceFormat
    {
        $value = $this->settings->value(new SettingContext($company, productCategoryId: $category?->getId()), ProductReferenceSettings::FORMAT);

        return new ReferenceFormat(\is_string($value) ? $value : ProductReferenceSettings::DEFAULT_FORMAT);
    }

    private function held(Company $company, string $reference): bool
    {
        return null !== $this->products->ofReferenceInCompany($reference, $company->getId());
    }
}
