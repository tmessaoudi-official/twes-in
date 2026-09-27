<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Legal\Application\LegalTextNotWritten;
use App\Legal\Application\ManageLegalTexts;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** @implements ProcessorInterface<null, PlatformLegalTextResource> */
final readonly class ValidateLegalTextProcessor implements ProcessorInterface
{
    public function __construct(private ManageLegalTexts $manage, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlatformLegalTextResource
    {
        $path = LegalTextPath::of($uriVariables);
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // The operation security expression runs first; this is the type guard, not the access check.
            throw new AccessDeniedException();
        }

        try {
            $text = $this->manage->validate($path->page, $path->language, $account->getId(), $account->getUserIdentifier());
        } catch (LegalTextNotWritten $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return PlatformLegalTextResource::of($text);
    }
}
