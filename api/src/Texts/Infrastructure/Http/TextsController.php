<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\Http;

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Application\RecordChanged;
use App\Shared\Domain\Exception\InvalidFilter;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\ApiPlatform\Output\RecordChangedProblem;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Texts\Application\Catalogue\TextCatalogue;
use App\Texts\Application\Catalogue\TextCatalogueEntry;
use App\Texts\Application\Catalogue\TextCatalogueQuery;
use App\Texts\Application\OverrideText;
use App\Texts\Application\ReadTextOverrides;
use App\Texts\Application\TextOverrideRefused;
use App\Texts\Application\TextValueRefused;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\ValueObject\TextAddress;
use App\Texts\Infrastructure\Security\TextsPermission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The texts worded otherwise than shipped: the installation's screens read by anyone, a company's by its members; the
 * installation's written by an operator, behind the step-up every platform write asks, a company's by whoever holds
 * company.texts. A key with dots and a page's file (`screen:two-factor`) travel in the path as they are, which an API
 * Platform route's `.{_format}` would not let through. Documented in TextsOpenApi.
 *
 * A change answers 200 with the text, the original it replaces and its version; 422 a violation on `value` for a
 * wording refused (TextValueRefused), or on `value` or `version` sent as neither a text nor a version; 422 `{error}` for a
 * text that cannot be worded otherwise (TextOverrideRefused); 409 `/problems/record-changed` over a version saved since;
 * 403 `step_up_required` for an operator without a proof given again a few minutes ago. The catalogue refuses an address
 * it does not read on the parameter it names, `filter_refused`, as every list does.
 */
#[AsController]
final readonly class TextsController
{
    private const array REQUIREMENTS = ['language' => '[A-Za-z]{2,3}(-[A-Za-z0-9]+)*', 'domain' => '[a-z]+(:[a-z0-9-]+)?', 'key' => '[A-Za-z0-9_.-]+'];

    public function __construct(
        private CompanyGuard $guard,
        private Security $security,
        private OverrideText $override,
        private ReadTextOverrides $read,
        private TextCatalogue $catalogue,
        #[Autowire(param: 'app.texts.catalogue_page_size')] private int $pageSize,
        #[Autowire(param: 'app.texts.catalogue_page_max')] private int $pageMax,
    ) {
    }

    /** Public: what a signed-out page shows is the installation's, the same for everyone. */
    #[Route('/api/texts/{language}', name: 'api_texts', requirements: self::REQUIREMENTS, methods: ['GET'])]
    public function installation(Request $request, string $language): Response
    {
        return $this->screens($request, null, $language);
    }

    #[Route('/api/companies/{companyId}/texts/{language}', name: 'api_company_texts', requirements: self::REQUIREMENTS, methods: ['GET'])]
    public function company(Request $request, string $companyId, string $language): Response
    {
        return $this->screens($request, $this->companyFor($companyId, TextsPermission::READ), $language);
    }

    #[Route('/api/platform/texts/{language}/catalogue', name: 'api_platform_text_catalogue', requirements: self::REQUIREMENTS, defaults: ['_api_respond' => true], methods: ['GET'])]
    public function installationCatalogue(Request $request, string $language): Response
    {
        $this->refuseUnlessOperator();

        return $this->catalogue($request, null, $language);
    }

    #[Route('/api/companies/{companyId}/texts/{language}/catalogue', name: 'api_company_text_catalogue', requirements: self::REQUIREMENTS, defaults: ['_api_respond' => true], methods: ['GET'])]
    public function companyCatalogue(Request $request, string $companyId, string $language): Response
    {
        return $this->catalogue($request, $this->companyFor($companyId, TextsPermission::WRITE), $language);
    }

    #[Route('/api/platform/texts/{language}/{domain}/{key}', name: 'api_platform_text_write', requirements: self::REQUIREMENTS, defaults: ['_api_respond' => true], methods: ['PUT'])]
    public function writeInstallation(Request $request, string $language, string $domain, string $key): Response
    {
        $this->refuseUnlessOperator();

        return $this->write($request, null, new TextAddress($language, $domain, $key));
    }

    #[Route('/api/platform/texts/{language}/{domain}/{key}', name: 'api_platform_text_reset', requirements: self::REQUIREMENTS, methods: ['DELETE'])]
    public function resetInstallation(string $language, string $domain, string $key): Response
    {
        $this->refuseUnlessOperator();
        $this->override->reset(null, new TextAddress($language, $domain, $key), $this->account()->getId());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/companies/{companyId}/texts/{language}/{domain}/{key}', name: 'api_company_text_write', requirements: self::REQUIREMENTS, defaults: ['_api_respond' => true], methods: ['PUT'])]
    public function writeCompany(Request $request, string $companyId, string $language, string $domain, string $key): Response
    {
        return $this->write($request, $this->companyFor($companyId, TextsPermission::WRITE), new TextAddress($language, $domain, $key));
    }

    #[Route('/api/companies/{companyId}/texts/{language}/{domain}/{key}', name: 'api_company_text_reset', requirements: self::REQUIREMENTS, methods: ['DELETE'])]
    public function resetCompany(string $companyId, string $language, string $domain, string $key): Response
    {
        $this->override->reset($this->companyFor($companyId, TextsPermission::WRITE), new TextAddress($language, $domain, $key), $this->account()->getId());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function screens(Request $request, ?Company $company, string $language): Response
    {
        if (!$this->read->speaks($language)) {
            throw new NotFoundHttpException(\sprintf('The screens do not speak %s.', $language));
        }
        $response = new JsonResponse($this->read->screens($company, $language));
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT);
        // Asked again each time, answered 304 while nothing changed: a page loads the texts at every start.
        $response->setEtag(hash('xxh128', (string) $response->getContent()));
        $response->headers->addCacheControlDirective('no-cache');
        null === $company ? $response->setPublic() : $response->setPrivate();
        $response->isNotModified($request);

        return $response;
    }

    /** `q`, `domain`, `changed`, `forms`, `page` from 1 and `itemsPerPage` up to app.texts.catalogue_page_max; anything else is refused. */
    private function catalogue(Request $request, ?Company $company, string $language): Response
    {
        if (!$this->read->speaks($language)) {
            throw new NotFoundHttpException(\sprintf('The screens do not speak %s.', $language));
        }
        $asked = $request->query;
        $page = $asked->getString('page', '1');
        $perPage = $asked->getString('itemsPerPage', (string) $this->pageSize);
        $search = $asked->has('q') ? $asked->getString('q') : null;
        $domain = $asked->has('domain') ? $asked->getString('domain') : null;
        $changed = $asked->getString('changed', '0');
        $forms = $asked->getString('forms', '0');
        if (1 !== preg_match('/^[1-9][0-9]{0,5}$/', $page)) {
            throw new InvalidFilter('page', 'a page is counted from 1');
        }
        if (1 !== preg_match('/^[1-9][0-9]{0,5}$/', $perPage) || (int) $perPage > $this->pageMax) {
            throw new InvalidFilter('itemsPerPage', \sprintf('a page holds 1 to %d texts', $this->pageMax));
        }
        foreach (['changed' => $changed, 'forms' => $forms] as $switch => $asked) {
            if (!\in_array($asked, ['0', '1', 'true', 'false'], true)) {
                throw new InvalidFilter($switch, 'a switch is 1 or 0');
            }
        }
        $query = new TextCatalogueQuery(TypedText::trimmedOrNull($search), TypedText::trimmedOrNull($domain), \in_array($changed, ['1', 'true'], true), \in_array($forms, ['1', 'true'], true), (int) $page, (int) $perPage);
        $found = $this->catalogue->page($company, $language, $query);

        return new JsonResponse([
            'items' => array_map(static fn (TextCatalogueEntry $entry): array => [
                'domain' => $entry->domain,
                'key' => $entry->key,
                'original' => $entry->original,
                'installation' => $entry->installation,
                'company' => $entry->company,
                'version' => $entry->version,
                'originalChanged' => $entry->originalChanged,
                'counted' => $entry->counted,
                'formsMissing' => $entry->formsMissing,
            ], $found['items']),
            'total' => $found['total'],
            'page' => $query->page,
            'itemsPerPage' => $query->perPage,
        ]);
    }

    private function write(Request $request, ?Company $company, TextAddress $address): Response
    {
        try {
            $body = json_decode($request->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }
        if (!\is_array($body) || !\is_string($body['value'] ?? null)) {
            throw DomainRule::refused('value', 'A text is written as a string.', 'invalid_value');
        }
        if (!\is_int($body['version'] ?? null) || $body['version'] < 0) {
            throw DomainRule::refused('version', 'A text names the version read, 0 when there was none.', 'invalid_value');
        }
        try {
            $override = $this->override->override($company, $address, $body['value'], $body['version'], $this->account()->getId());
        } catch (TextValueRefused $refused) {
            throw DomainRule::refused('value', $refused->getMessage(), $refused->reason, previous: $refused);
        } catch (TextOverrideRefused $refused) {
            // error-codes: TextOverrideRefused
            return new JsonResponse(['error' => $refused->reason, 'detail' => $refused->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RecordChanged $changed) {
            // A plain route's exception would be Symfony's error page: the problem is answered as API Platform words it.
            $problem = new RecordChangedProblem($changed);

            return new JsonResponse(
                ['type' => $problem->getType(), 'title' => $problem->getTitle(), 'status' => $problem->getStatus(), 'detail' => $problem->getDetail()],
                $problem->getStatus(),
                ['Content-Type' => 'application/problem+json'],
            );
        }

        return new JsonResponse(self::described($override));
    }

    /** @return array{value: string, original: string, version: int} */
    private static function described(TextOverride $override): array
    {
        return ['value' => $override->getValue(), 'original' => $override->getOriginal(), 'version' => $override->getVersion()];
    }

    private function companyFor(string $companyId, string $permission): Company
    {
        return $this->guard->companyForActing(CompanyPath::identifier(['companyId' => $companyId], 'companyId'), $permission);
    }

    /** Only an operator; the step-up every platform write asks is PlatformWriteStepUpListener's, before this runs. */
    private function refuseUnlessOperator(): void
    {
        if (!$this->security->isGranted(TextsPermission::PLATFORM)) {
            throw new AccessDeniedHttpException('Only an operator words the installation\'s texts.');
        }
    }

    private function account(): SecurityUser
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            // access_control requires a session on every write and on a company's read.
            throw new \LogicException('The texts are written behind the firewall.');
        }

        return $account;
    }
}
