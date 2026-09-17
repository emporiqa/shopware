<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;

interface CmsPageFormatterInterface
{
    /** CMS layout types of categories that are synced as pages. */
    public const SHOP_PAGE_LAYOUT_TYPES = ['page', 'landingpage'];

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
     * Returns null when the category is not reachable in any synced sales channel
     * (outside every channel's navigation, footer and service trees) or is a tree root.
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
