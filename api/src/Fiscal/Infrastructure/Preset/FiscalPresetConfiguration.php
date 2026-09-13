<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Preset;

use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\IdentifierCheck;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\TaxKind;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The shape of a preset file: which keys exist, which are required, and the closed value sets. What depends on
 * several values at once (a kind needing a rate, an amount no finer than the currency) is PresetReader's.
 */
final class FiscalPresetConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('fiscal_preset');
        $root = $tree->getRootNode();

        $root->children()
            ->scalarNode('country')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('currency')->isRequired()->cannotBeEmpty()->end()
            ->integerNode('minor_unit')->isRequired()->min(0)->max(4)->end()
            ->arrayNode('document_languages')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()
            ->arrayNode('rounding')->isRequired()
                ->children()
                    ->enumNode('mode')->isRequired()->values(['half_up'])->end()
                    ->enumNode('vat_point')->isRequired()->values(array_map(static fn (RoundingPoint $point) => $point->value, RoundingPoint::cases()))->end()
                    ->enumNode('tax_basis')->isRequired()->values(array_map(static fn (TaxBasis $basis) => $basis->value, TaxBasis::cases()))->end()
                ->end()
            ->end()
            ->arrayNode('identifiers')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('label_key')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('pattern')->isRequired()->cannotBeEmpty()->end()
                        ->arrayNode('required_for')->scalarPrototype()->end()->end()
                        ->enumNode('check')->values(array_map(static fn (IdentifierCheck $check) => $check->value, IdentifierCheck::cases()))->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('tax_components')->isRequired()->requiresAtLeastOneElement()
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('code')->isRequired()->cannotBeEmpty()->end()
                        ->append($this->names())
                        ->enumNode('kind')->isRequired()->values(array_map(static fn (TaxKind $kind) => $kind->value, TaxKind::cases()))->end()
                        ->enumNode('family')->isRequired()->values(array_map(static fn (TaxFamily $family) => $family->value, TaxFamily::cases()))->end()
                        ->scalarNode('rate')->defaultNull()->end()
                        ->scalarNode('amount')->defaultNull()->end()
                        ->scalarNode('threshold')->defaultNull()->end()
                        ->booleanNode('enters_vat_base')->defaultFalse()->end()
                        ->booleanNode('is_default')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end()
            ->append($this->regimes('customer_tax_regimes'))
            ->append($this->regimes('company_vat_regimes'))
            ->arrayNode('mentions')
                ->children()
                    ->arrayNode('invoice')->scalarPrototype()->end()->end()
                ->end()
            ->end()
            ->arrayNode('establishment')->isRequired()
                ->children()
                    ->scalarNode('default_code')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('code_pattern')->isRequired()->cannotBeEmpty()->end()
                ->end()
            ->end()
            ->arrayNode('numbering')->isRequired()
                ->useAttributeAsKey('document_type')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('format')->isRequired()->cannotBeEmpty()->end()
                        ->enumNode('reset')->isRequired()->values(['yearly', 'monthly', 'never'])->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('units')->isRequired()->requiresAtLeastOneElement()
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('code')->isRequired()->cannotBeEmpty()->end()
                        ->append($this->names())
                        ->integerNode('decimals')->isRequired()->min(0)->max(3)->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $tree;
    }

    /** A label in every language the product speaks. */
    private function names(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('names'))->getRootNode();
        $node->isRequired()
            ->children()
                ->scalarNode('fr')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('en')->isRequired()->cannotBeEmpty()->end()
            ->end();

        return $node;
    }

    private function regimes(string $name): ArrayNodeDefinition
    {
        $node = (new TreeBuilder($name))->getRootNode();
        $node->isRequired()->requiresAtLeastOneElement()
            ->arrayPrototype()
                ->children()
                    ->scalarNode('code')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('label_key')->isRequired()->cannotBeEmpty()->end()
                    ->arrayNode('excluded_families')->scalarPrototype()->end()->end()
                    ->scalarNode('mention_key')->defaultNull()->end()
                ->end()
            ->end();

        return $node;
    }
}
