<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;

interface CmsPageFormatterInterface
{
    /**
     * Returns null when the landing page is not reachable in any synced sales
     * channel (not assigned to one).
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>|null
     */
    public function formatLandingPage(
        LandingPageEntity $landingPage,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): ?array;

    /**
     * Any active category of type "page" is synced, whatever CMS layout it uses
     * (a listing layout can carry its own text and FAQ blocks above or below the
     * product grid). Returns null when the category is not reachable in any synced
     * sales channel (outside every channel's navigation, footer and service trees),
     * or has no content of its own (a plain product listing) - the exception being
     * a channel's navigation root, which is the storefront home page and is always
     * synced when reachable, content or not. Footer and service tree roots are
     * never pages.
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>|null
     */
    public function formatShopPage(
        CategoryEntity $category,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatPageDelete(string $pageId): array;
}
