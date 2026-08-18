<?php

declare(strict_types=1);

namespace Nowo\BlogKitBundle\Controller\Admin;

use Nowo\FormKitBundle\Form\AbstractGetFilterType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET list filters (REQ-CSRF-002 / REQ-FORMKIT-004) — no CSRF; query string is the source of truth.
 *
 * Prefer {@see resolveAdminListFilters()} with a FormKit {@see AbstractGetFilterType}.
 *
 * @mixin \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
 */
trait AdminListFilterTrait
{
    /**
     * Bind a rootless GET filter form and normalize non-empty values.
     *
     * @param FormInterface<mixed> $filterForm
     * @param list<string> $allowedKeys Filter field names accepted from the form / query string
     *
     * @return array<string, string> Normalized filters
     */
    protected function resolveAdminListFilters(
        Request $request,
        FormInterface $filterForm,
        array $allowedKeys,
    ): array {
        $filterForm->handleRequest($request);

        if ($filterForm->isSubmitted() && $filterForm->isValid()) {
            $filters = [];

            foreach ($allowedKeys as $allowedKey) {
                if (!$filterForm->has($allowedKey)) {
                    continue;
                }

                $value = trim((string) ($filterForm->get($allowedKey)->getData() ?? ''));

                if ($value !== '') {
                    $filters[$allowedKey] = $value;
                }
            }

            return $filters;
        }

        return $this->handleAdminListFilters($request, '', $allowedKeys);
    }

    /**
     * @param list<string> $allowedKeys Filter field names accepted from the query string
     * @param array<string, mixed> $routeParams Unused; kept for host override compatibility
     * @param list<string> $alwaysKeep Unused; kept for host override compatibility
     *
     * @return array<string, string> Normalized filters from the query string
     */
    protected function handleAdminListFilters(
        Request $request,
        string $routeName,
        array $allowedKeys,
        array $routeParams = [],
        array $alwaysKeep = [],
    ): array {
        unset($routeName, $routeParams, $alwaysKeep);

        $filters = [];

        foreach ($allowedKeys as $allowedKey) {
            $value = trim($request->query->getString($allowedKey));

            if ($value !== '') {
                $filters[$allowedKey] = $value;
            }
        }

        return $filters;
    }
}
