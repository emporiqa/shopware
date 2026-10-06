<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Names the headless (API-type) sales channels that show products, which the
 * plugin never syncs (see ChannelResolver::autoDetect()), so Test connection
 * and the Sync tab can say so instead of staying silent. Shopware's own
 * installer creates an empty "Headless" channel on most shops; it shows no
 * products and is not named.
 */
class HeadlessChannelNotice
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    /**
     * @return list<string>
     */
    public function channelsWithProducts(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_API));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('productVisibilities.id', null),
        ]));

        $salesChannels = $this->salesChannelRepository->search($criteria, $context)->getEntities();
        $names = [];
        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($salesChannel->getTypeId() !== Defaults::SALES_CHANNEL_TYPE_API) {
                continue;
            }
            $names[] = (string) ($salesChannel->getTranslation('name') ?? $salesChannel->getName() ?? $salesChannel->getId());
        }

        return $names;
    }

    /**
     * @param list<string> $names
     */
    public static function message(array $names): string
    {
        return \sprintf(
            'Not synced (headless sales channel): %s. Shopware creates no product page addresses (SEO URLs) for headless channels, so the chat could not link to products there; only Storefront sales channels are synced. The chat widget comes with the Storefront theme; on a frontend Shopware does not render, add it with the embed code: https://emporiqa.com/docs/widget-embedding/',
            '"' . implode('", "', $names) . '"',
        );
    }
}
