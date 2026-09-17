<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Licensing\Application\ManagePayments;
use App\Licensing\Domain\InvalidPayment;
use App\Licensing\Domain\PaymentAlreadyDeclared;
use App\Licensing\Domain\SubscriptionNotManaged;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The company says it paid. Whoever may pay the company's bills may declare a payment, whatever the subscription lets
 * the company do otherwise: a locked company that could not declare one would have no way back.
 *
 * @implements ProcessorInterface<PaymentDeclarationResource, PaymentDeclarationResource>
 */
final readonly class DeclarePaymentProcessor implements ProcessorInterface
{
    public function __construct(private CompanyGuard $guard, private ManagePayments $payments)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PaymentDeclarationResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanySubscriptionResource::PAY_PERMISSION);

        try {
            $declaration = $this->payments->declare($company, $data->payment(), $this->guard->account()->getId());
        } catch (SubscriptionNotManaged $notManaged) {
            throw new NotFoundHttpException('Licensing does not manage this company.', $notManaged);
        } catch (PaymentAlreadyDeclared $waiting) {
            throw new ConflictHttpException($waiting->getMessage(), $waiting);
        } catch (InvalidPayment $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return PaymentDeclarationResource::of($declaration);
    }
}
