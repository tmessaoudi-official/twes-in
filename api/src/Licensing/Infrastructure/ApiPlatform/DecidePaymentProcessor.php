<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Licensing\Application\ManagePayments;
use App\Licensing\Domain\InvalidPayment;
use App\Licensing\Domain\PaymentNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * The operator answers a declared payment; the operation's security has already required one. A confirmation carries
 * the covered time forward by the periods it names, a rejection ends the hold at once. Either is made once.
 *
 * @implements ProcessorInterface<PaymentDeclarationResource, PaymentDeclarationResource>
 */
final readonly class DecidePaymentProcessor implements ProcessorInterface
{
    public function __construct(private ManagePayments $payments, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentDeclarationResource
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }
        $raw = $uriVariables['declarationId'] ?? null;
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            throw new NotFoundHttpException('No such payment declaration.');
        }
        $confirmed = PaymentDeclarationResource::CONFIRM === $operation->getName();

        try {
            $declaration = $this->payments->decide(Uuid::fromString($raw), $confirmed, $account->getId(), $data->decisionNote, $data->periodsCovered());
        } catch (PaymentNotFound $notFound) {
            throw new NotFoundHttpException($notFound->getMessage(), $notFound);
        } catch (InvalidPayment $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return PaymentDeclarationResource::of($declaration);
    }
}
