<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

interface CmsContentResolverInterface
{
    /**
     * Text content of a category's CMS page as the storefront resolves it for the
     * given sales channel and language, or null when it cannot be resolved.
     */
    public function resolveCategoryContent(
        string $categoryId,
        string $salesChannelId,
        string $languageId,
        string $currencyId,
    ): ?string;

    /**
     * Text content of a landing page's CMS page as the storefront resolves it for
     * the given sales channel and language, or null when it cannot be resolved.
     */
    public function resolveLandingPageContent(
        string $landingPageId,
        string $salesChannelId,
        string $languageId,
        string $currencyId,
    ): ?string;
}
