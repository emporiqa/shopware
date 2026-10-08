<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Emporiqa\ShopwarePlugin\Event\WidgetParamsEvent;
use Emporiqa\ShopwarePlugin\Service\ChannelResolverInterface;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class StorefrontSubscriber implements EventSubscriberInterface
{
    /** @var array<string, string|null> languageId => locale code */
    private array $localeCodes = [];

    /**
     * @param EntityRepository<LanguageCollection> $languageRepository
     */
    public function __construct(
        private readonly ConfigServiceInterface $config,
        private readonly ChannelResolverInterface $channelResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityRepository $languageRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $storeId = $this->config->getStoreId($salesChannelId);

        if ($storeId === '') {
            return;
        }

        if (!$this->isSalesChannelEnabled($salesChannelId) || !$this->isLanguageEnabled($event->getSalesChannelContext())) {
            return;
        }

        $request = $event->getRequest();
        $languageCode = $request->getLocale();

        // Derive widget URL from webhook URL
        $webhookUrl = $this->config->getWebhookUrl($salesChannelId);
        $parsedUrl = parse_url($webhookUrl);
        if (!\is_array($parsedUrl) || !isset($parsedUrl['host']) || strtolower($parsedUrl['scheme'] ?? '') !== 'https') {
            $parsedUrl = ['host' => 'emporiqa.com'];
        }
        $widgetBaseUrl = 'https://' . $parsedUrl['host'];

        $widgetChannel = $this->channelResolver->resolveChannelKey($salesChannelId);

        // Get current currency
        $currency = $event->getSalesChannelContext()->getCurrency();
        $currencyIso = $currency->getIsoCode();

        // A sales channel domain with a path (https://shop.example/de) serves
        // the plugin routes under that path too.
        $basePath = rtrim((string) parse_url((string) $request->attributes->get(RequestTransformer::STOREFRONT_URL, ''), \PHP_URL_PATH), '/');

        $emporiqaConfig = [
            'storeId' => $storeId,
            'language' => $languageCode,
            'channel' => $widgetChannel,
            'currency' => $currencyIso,
            'widgetBaseUrl' => $widgetBaseUrl,
            'cartApiUrl' => $basePath . '/emporiqa/api/cart',
            'userTokenUrl' => $basePath . '/emporiqa/api/user-token',
        ];

        $widgetParamsEvent = new WidgetParamsEvent($emporiqaConfig, $event->getSalesChannelContext());
        $this->eventDispatcher->dispatch($widgetParamsEvent);
        $emporiqaConfig = $widgetParamsEvent->getParams();

        $event->setParameter('emporiqaConfig', $emporiqaConfig);
    }

    private function isSalesChannelEnabled(string $salesChannelId): bool
    {
        $enabledSalesChannels = $this->config->getEnabledSalesChannels();

        return $enabledSalesChannels === [] || \in_array($salesChannelId, $enabledSalesChannels, true);
    }

    private function isLanguageEnabled(SalesChannelContext $salesChannelContext): bool
    {
        $enabledLanguages = $this->config->getEnabledLanguages();
        if ($enabledLanguages === []) {
            return true;
        }

        $localeCode = $this->resolveLocaleCode($salesChannelContext);

        // Unknown locale: keep the widget rather than hide it by mistake.
        return $localeCode === null || \in_array($localeCode, $enabledLanguages, true);
    }

    /**
     * Same criterion as the sync filter: the locale code of the storefront language.
     */
    private function resolveLocaleCode(SalesChannelContext $salesChannelContext): ?string
    {
        $languageId = $salesChannelContext->getLanguageId();
        if (\array_key_exists($languageId, $this->localeCodes)) {
            return $this->localeCodes[$languageId];
        }

        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        $language = $this->languageRepository
            ->search($criteria, $salesChannelContext->getContext())
            ->getEntities()
            ->first();
        $locale = $language instanceof LanguageEntity ? $language->getLocale() : null;

        return $this->localeCodes[$languageId] = $locale?->getCode();
    }
}
