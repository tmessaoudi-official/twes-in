<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Legal\Application\EmptyLegalText;
use App\Legal\Application\LegalLanguages;
use App\Legal\Application\LegalTextTooLong;
use App\Legal\Application\ManageLegalTexts;
use App\Legal\Infrastructure\ApiPlatform\Payload\LegalTextPath;
use App\Legal\Infrastructure\ApiPlatform\Resource\PlatformLegalTextResource;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** @implements ProcessorInterface<PlatformLegalTextResource, PlatformLegalTextResource> */
final readonly class WriteLegalTextProcessor implements ProcessorInterface
{
    public function __construct(private ManageLegalTexts $manage, private Security $security, private LegalLanguages $languages)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlatformLegalTextResource
    {
        $path = LegalTextPath::of($uriVariables, $this->languages);
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // The operation security expression runs first; this is the type guard, not the access check.
            throw new AccessDeniedException();
        }

        try {
            $text = $this->manage->write($path->page, $path->language, $data->body, $account->getId(), $account->getUserIdentifier());
        } catch (EmptyLegalText $empty) {
            throw DomainRule::refused('body', $empty->getMessage(), 'value_required', [], $empty);
        } catch (LegalTextTooLong $long) {
            throw DomainRule::refused('body', $long->getMessage(), 'invalid_text', ['max' => $long->maxLength], $long);
        }

        return PlatformLegalTextResource::of($text);
    }
}
