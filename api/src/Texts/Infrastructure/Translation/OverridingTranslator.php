<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Translation;

use App\Tenancy\Domain\Model\Company;
use App\Texts\Application\ShippedTexts;
use App\Texts\Application\TextsInForce;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\Repository\TextOverrideRepository;
use App\Texts\Domain\Service\TextLocks;
use App\Texts\Domain\ValueObject\TextAddress;
use App\Texts\Domain\ValueObject\TextSyntax;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\Formatter\MessageFormatterInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lays the wording of the company a text is written for, then the installation's, over what the API writes from its own
 * domains (`emails`, `pdf`, `imports`, `roles`), each filled in as the shipped text is. A locked text, the fiscal domain
 * whole, stays the release's whatever a row says. Each level's wording is read once a request, a mail or a message,
 * and forgotten when the worker resets.
 */
#[AsDecorator('translator')]
final class OverridingTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface, TextsInForce, ResetInterface
{
    private ?Uuid $companyId = null;

    /** @var array<string, array<string, string>> each level's wording, by `<company or ''>|<language>`, then `<domain>|<key>` */
    private array $read = [];

    public function __construct(
        #[AutowireDecorated] private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        #[Autowire(service: 'translator.formatter')] private readonly MessageFormatterInterface $formatter,
        private readonly TextOverrideRepository $overrides,
        private readonly TextLocks $locks,
        private readonly ShippedTexts $shipped,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function for(?Uuid $companyId, callable $write): mixed
    {
        $before = $this->companyId;
        $this->companyId = $companyId;
        try {
            return $write();
        } finally {
            $this->companyId = $before;
        }
    }

    /** @param array<array-key, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $language = str_replace('_', '-', $locale ?? $this->inner->getLocale());
        $address = new TextAddress($language, $domain ?? 'messages', $id);
        $worded = $this->mayBeWorded($address) ? $this->worded($address) : null;

        return null === $worded ? $this->inner->trans($id, $parameters, $domain, $locale) : $this->formatter->format($worded, $locale ?? $this->inner->getLocale(), $parameters);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }

    public function reset(): void
    {
        $this->companyId = null;
        $this->read = [];
        if ($this->inner instanceof ResetInterface) {
            $this->inner->reset();
        }
    }

    /** One of the API's own texts, which the release ships and does not lock; the framework's messages never are. */
    private function mayBeWorded(TextAddress $address): bool
    {
        return TextSyntax::Api === $address->syntax()
            && \in_array($address->domain, $this->shipped->domains(), true)
            && !$this->locks->locked($address);
    }

    /** The company's wording, else the installation's, else none. */
    private function worded(TextAddress $address): ?string
    {
        $at = $address->domain.'|'.$address->key;
        if (null !== $this->companyId) {
            $own = $this->level($this->companyId, $address->language)[$at] ?? null;
            if (null !== $own) {
                return $own;
            }
        }

        return $this->level(null, $address->language)[$at] ?? null;
    }

    /** @return array<string, string> */
    private function level(?Uuid $companyId, string $language): array
    {
        $key = ($companyId?->toRfc4122() ?? '').'|'.$language;
        if (!isset($this->read[$key])) {
            // A reference, never a load: the rows are read by the company's id alone.
            $company = null === $companyId ? null : $this->entityManager->getReference(Company::class, $companyId);
            $this->read[$key] = [];
            foreach ($this->overrides->of($company, $language) as $override) {
                $this->read[$key][self::at($override)] = $override->getValue();
            }
        }

        return $this->read[$key];
    }

    private static function at(TextOverride $override): string
    {
        $address = $override->address();

        return $address->domain.'|'.$address->key;
    }
}
