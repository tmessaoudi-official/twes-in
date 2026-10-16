<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Every revision says which version of the record it revises (docs/SPEC.md § 3 « Saving over someone else »): its PUT
 * takes a required `version` in the RecordVersion group, so a save of a version someone else saved since answers 409
 * instead of overwriting it. The PUTs that take none say why, and those not yet done are struck off as they are.
 */
final class RecordVersionsTest extends KernelTestCase
{
    /** Taking no version, each for its reason. */
    private const array WITHOUT = [
        '/companies/{companyId}/modules/{moduleKey}/interest' => 'a person\'s own wish to be told, where the last word is what they want',
        '/me/company-at-sign-in' => 'a person\'s own pin of the company they open at sign-in, where the last word is what they want',
        '/companies/{companyId}/modules/{moduleKey}' => 'one switch of the company, on or off, set as a whole',
        '/companies/{companyId}/security' => 'one switch of the company, set as a whole; it still moves the version the profile form is checked against',
        '/companies/{companyId}/closing' => 'one date of the company, set as a whole; it still moves the version the profile form is checked against',
    ];

    /** Not done yet: each slice strikes its own off. */
    private const array WAITING = [
        '/companies/{companyId}/custom-fields/{fieldId}',
        '/companies/{companyId}/tax-components/{componentId}',
        '/companies/{companyId}/units/{unitId}',
        '/platform/companies/{companyId}/subscription',
        '/companies/{companyId}/customers/{customerId}/contacts/{contactId}',
        '/companies/{companyId}/customer-groups/{groupId}',
        '/companies/{companyId}/delivery-notes/{deliveryNoteId}',
        '/companies/{companyId}/expense-categories/{categoryId}',
        '/companies/{companyId}/products/{productId}/home-locations',
        '/companies/{companyId}/products/{productId}/home-locations/{establishmentId}',
        '/companies/{companyId}/products/{productId}/reorder-points/{establishmentId}',
        '/companies/{companyId}/stock-locations/{locationId}',
        '/companies/{companyId}/stock-drawings/{drawingId}',
        '/companies/{companyId}/stock-floors/{floorId}',
        '/companies/{companyId}/stock-structures/{structureId}',
        '/companies/{companyId}/price-lists/{priceListId}',
        '/companies/{companyId}/products/{productId}/barcodes',
        '/companies/{companyId}/product-categories/{categoryId}',
        '/companies/{companyId}/quotes/{quoteId}',
        '/companies/{companyId}/recurring-invoices/{recurringInvoiceId}',
        '/companies/{companyId}/recurring-expenses/{recurringExpenseId}',
        '/companies/{companyId}/settings/{key}',
        '/platform/settings/{key}',
        '/companies/{companyId}/profile',
        '/companies/{companyId}/establishments/{establishmentId}',
        '/companies/{companyId}/numbering-series/{seriesId}',
        '/companies/{companyId}/roles/{roleId}',
    ];

    public function testEveryRevisionSaysWhichVersionItRevises(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $validator = $container->get(ValidatorInterface::class);
        $metadata = $container->get(ResourceMetadataCollectionFactoryInterface::class);

        $found = [];
        $wrong = [];
        foreach ($container->get(ResourceNameCollectionFactoryInterface::class)->create() as $class) {
            foreach ($metadata->create($class) as $resource) {
                foreach ($resource->getOperations() ?? [] as $operation) {
                    if (!$operation instanceof Put) {
                        continue;
                    }
                    $template = (string) $operation->getUriTemplate();
                    $found[] = $template;
                    $declared = $operation->getInput();
                    $input = \is_array($declared) && isset($declared['class']) ? $declared['class'] : $operation->getClass();
                    $takes = \is_string($input) && self::takesAVersion($validator->getMetadataFor($input), $operation->getDenormalizationContext() ?? [], $operation->getValidationContext() ?? []);
                    if (\array_key_exists($template, self::WITHOUT) || \in_array($template, self::WAITING, true)) {
                        if ($takes) {
                            $wrong[] = "$template takes its version: strike it off the list";
                        }
                        continue;
                    }
                    if (!$takes) {
                        $wrong[] = "$template revises without a required version in ".RecordVersion::REVISE;
                    }
                }
            }
        }

        // Fewer PUTs found than there are means the discovery broke, not that the rule holds.
        self::assertGreaterThanOrEqual(37, \count($found), 'every PUT is read');
        foreach ([...array_keys(self::WITHOUT), ...self::WAITING] as $listed) {
            if (!\in_array($listed, $found, true)) {
                $wrong[] = "$listed is listed but no PUT has that address";
            }
        }
        self::assertSame([], $wrong);
    }

    /**
     * @param array<mixed> $denormalization
     * @param array<mixed> $validation
     */
    private static function takesAVersion(mixed $constraints, array $denormalization, array $validation): bool
    {
        if (!$constraints instanceof ClassMetadata || !\in_array(RecordVersion::REVISE, (array) ($denormalization['groups'] ?? []), true) || !\in_array(RecordVersion::REVISE, (array) ($validation['groups'] ?? []), true)) {
            return false;
        }
        foreach ($constraints->getPropertyMetadata('version') as $property) {
            foreach ($property->findConstraints(RecordVersion::REVISE) as $constraint) {
                if ($constraint instanceof NotNull) {
                    return true;
                }
            }
        }

        return false;
    }
}
