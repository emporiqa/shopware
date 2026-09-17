<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Contracts\Service\ResetInterface;

class ChannelResolver implements ChannelResolverInterface, ResetInterface
{
    /** @var array<string, string>|null */
    private ?array $resolvedMapping = null;

    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    public function resolveChannelKey(string $salesChannelId): string
    {
        $mapping = $this->getMapping();

        if (isset($mapping[$salesChannelId])) {
            return $mapping[$salesChannelId];
        }

        return self::slugify($salesChannelId);
    }

    public function getMapping(): array
    {
        if ($this->resolvedMapping !== null) {
            return $this->resolvedMapping;
        }

        $explicit = $this->config->getChannelMapping();
        if (!empty($explicit)) {
            $this->resolvedMapping = $explicit;

            return $this->resolvedMapping;
        }

        $this->resolvedMapping = $this->autoDetect();

        return $this->resolvedMapping;
    }

    /**
     * Auto-detect channel mapping from active storefront sales channels.
     * Every channel gets a slugified name, no empty keys.
     *
     * @return array<string, string>
     */
    private function autoDetect(): array
    {
        $context = Context::createCLIContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

        $salesChannels = $this->salesChannelRepository->search($criteria, $context);

        $mapping = [];
        $used = [];
        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            if ($salesChannel->getTypeId() === Defaults::SALES_CHANNEL_TYPE_API) {
                continue;
            }

            // Two channels with the same name (e.g. a cloned channel) must not
            // collapse into one Emporiqa channel.
            $slug = self::slugify($salesChannel->getTranslation('name') ?? $salesChannel->getName() ?? $salesChannel->getId());
            $candidate = $slug;
            for ($suffix = 2; isset($used[$candidate]); $suffix++) {
                $candidate = $slug . '-' . $suffix;
            }

            $used[$candidate] = true;
            $mapping[$salesChannel->getId()] = $candidate;
        }

        return $mapping;
    }

    public function reset(): void
    {
        $this->resolvedMapping = null;
    }

    private static function slugify(string $name): string
    {
        $slug = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name) ?: strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
