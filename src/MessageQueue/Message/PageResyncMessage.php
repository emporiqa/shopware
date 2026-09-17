<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\MessageQueue\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Pages to (re-)sync in the background: by CMS layout (every page using the
 * layout), or by landing page / category id after a write or SEO URL change.
 */
class PageResyncMessage implements AsyncMessageInterface
{
    /**
     * @param list<string> $cmsPageIds layouts whose pages are re-synced
     * @param list<string> $landingPageIds
     * @param list<string> $categoryIds
     * @param list<string> $createdIds landing page / category ids that were just inserted, nothing exists remotely for them yet
     * @param list<string> $structuralCategoryIds category ids whose type, layout, parent or active flag changed
     */
    public function __construct(
        private readonly array $cmsPageIds = [],
        private readonly array $landingPageIds = [],
        private readonly array $categoryIds = [],
        private readonly array $createdIds = [],
        private readonly array $structuralCategoryIds = [],
    ) {
    }

    /** @return list<string> */
    public function getCmsPageIds(): array
    {
        return $this->cmsPageIds;
    }

    /** @return list<string> */
    public function getLandingPageIds(): array
    {
        return $this->landingPageIds;
    }

    /** @return list<string> */
    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    /** @return list<string> */
    public function getCreatedIds(): array
    {
        return $this->createdIds;
    }

    /** @return list<string> */
    public function getStructuralCategoryIds(): array
    {
        return $this->structuralCategoryIds;
    }

    public function isEmpty(): bool
    {
        return $this->cmsPageIds === [] && $this->landingPageIds === [] && $this->categoryIds === [];
    }
}
