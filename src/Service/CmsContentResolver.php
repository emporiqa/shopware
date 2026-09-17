<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoaderInterface;
use Shopware\Core\Content\Cms\SalesChannel\Struct\HtmlStruct;
use Shopware\Core\Content\Cms\SalesChannel\Struct\TextStruct;
use Shopware\Core\Content\LandingPage\LandingPageCollection;
use Shopware\Core\Content\LandingPage\LandingPageDefinition;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves CMS page content through Shopware's own CMS page loader, so mapped
 * fields, per-page slot overrides, translation fallback and element resolvers
 * (including those of third-party plugins) produce what the storefront shows.
 */
class CmsContentResolver implements CmsContentResolverInterface, ResetInterface
{
    /** Core CMS elements that carry no page text. */
    private const NON_TEXT_ELEMENT_TYPES = [
        'buy-box',
        'category-navigation',
        'cross-selling',
        'image',
        'image-gallery',
        'image-slider',
        'location-renderer',
        'manufacturer-logo',
        'product-box',
        'product-description-reviews',
        'product-listing',
        'product-name',
        'sidebar-filter',
        'vimeo-video',
        'youtube-video',
    ];

    /** Config keys of custom elements that hold markup settings rather than text. */
    private const NON_TEXT_CONFIG_KEY_PATTERN = '/(class|css|url|link|href|color|colour|media|image|icon|id|mode|align|width|height|size|style|target|source)$/i';

    /** Config keys whose string values are visible text even when short. */
    private const TEXT_CONFIG_KEYS = ['title', 'subtitle', 'headline', 'subheadline', 'heading', 'text', 'content', 'question', 'answer', 'label', 'description', 'caption', 'confirmationText', 'intro', 'summary'];

    /** @var array<string, SalesChannelContext|null> */
    private array $salesChannelContexts = [];

    /**
     * Sales channel repositories, so the entity is loaded exactly as the storefront
     * loads it (per-channel default layouts, channel assignment filters).
     *
     * @param SalesChannelRepository<CategoryCollection> $categoryRepository
     * @param SalesChannelRepository<LandingPageCollection> $landingPageRepository
     */
    public function __construct(
        private readonly SalesChannelCmsPageLoaderInterface $cmsPageLoader,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly SalesChannelRepository $categoryRepository,
        private readonly SalesChannelRepository $landingPageRepository,
        private readonly CategoryDefinition $categoryDefinition,
        private readonly LandingPageDefinition $landingPageDefinition,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveCategoryContent(
        string $categoryId,
        string $salesChannelId,
        string $languageId,
        string $currencyId,
    ): ?string {
        $salesChannelContext = $this->getSalesChannelContext($salesChannelId, $languageId, $currencyId);
        if ($salesChannelContext === null) {
            return null;
        }

        try {
            $criteria = new Criteria([$categoryId]);
            $criteria->addAssociation('translations');

            $category = $this->categoryRepository->search($criteria, $salesChannelContext)->getEntities()->get($categoryId);
            if (!$category instanceof CategoryEntity || $category->getCmsPageId() === null) {
                return null;
            }

            $slotConfig = $this->mergeCategorySlotConfig($category, $salesChannelContext);

            return $this->loadContent(
                $category->getCmsPageId(),
                $salesChannelContext,
                $slotConfig,
                $this->categoryDefinition,
                $category,
            );
        } catch (\Throwable $e) {
            $this->logFailure('category', $categoryId, $e);

            return null;
        }
    }

    public function resolveLandingPageContent(
        string $landingPageId,
        string $salesChannelId,
        string $languageId,
        string $currencyId,
    ): ?string {
        $salesChannelContext = $this->getSalesChannelContext($salesChannelId, $languageId, $currencyId);
        if ($salesChannelContext === null) {
            return null;
        }

        try {
            $landingPage = $this->landingPageRepository
                ->search(new Criteria([$landingPageId]), $salesChannelContext)
                ->getEntities()
                ->get($landingPageId);
            if (!$landingPage instanceof LandingPageEntity || $landingPage->getCmsPageId() === null) {
                return null;
            }

            $slotConfig = $landingPage->getTranslation('slotConfig');

            return $this->loadContent(
                $landingPage->getCmsPageId(),
                $salesChannelContext,
                \is_array($slotConfig) ? $slotConfig : null,
                $this->landingPageDefinition,
                $landingPage,
            );
        } catch (\Throwable $e) {
            $this->logFailure('landing page', $landingPageId, $e);

            return null;
        }
    }

    public function reset(): void
    {
        $this->salesChannelContexts = [];
    }

    /**
     * @param array<string, mixed>|null $slotConfig
     */
    private function loadContent(
        string $cmsPageId,
        SalesChannelContext $salesChannelContext,
        ?array $slotConfig,
        EntityDefinition $definition,
        Entity $entity,
    ): ?string {
        $request = new Request();
        $resolverContext = new EntityResolverContext($salesChannelContext, $request, $definition, $entity);

        $cmsPage = $this->cmsPageLoader
            ->load($request, new Criteria([$cmsPageId]), $salesChannelContext, $slotConfig, $resolverContext)
            ->getEntities()
            ->first();

        if (!$cmsPage instanceof CmsPageEntity) {
            return null;
        }

        return $this->extractText($cmsPage);
    }

    private function extractText(CmsPageEntity $cmsPage): string
    {
        $texts = [];

        foreach ($cmsPage->getSections() ?? [] as $section) {
            foreach ($section->getBlocks() ?? [] as $block) {
                foreach ($block->getSlots() ?? [] as $slot) {
                    $data = $slot->getData();

                    if ($data instanceof TextStruct || $data instanceof HtmlStruct) {
                        $content = trim((string) $data->getContent());
                    } elseif (\in_array($slot->getType(), self::NON_TEXT_ELEMENT_TYPES, true)) {
                        continue;
                    } else {
                        $customTexts = [];
                        $this->collectConfigText($slot->getConfig() ?? [], $customTexts);
                        $content = implode("\n\n", $customTexts);
                    }

                    if ($content !== '') {
                        $texts[] = $content;
                    }
                }
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Collect readable strings from a custom element's config (e.g. FAQ accordion
     * items), skipping mapped field references and styling/media settings.
     *
     * @param array<mixed> $config
     * @param list<string> $texts
     * @param string|null $parentKey key under which a nested value (e.g. {value: ...}) sits
     */
    private function collectConfigText(array $config, array &$texts, ?string $parentKey = null): void
    {
        if (($config['source'] ?? null) === 'mapped') {
            return;
        }

        foreach ($config as $key => $value) {
            if (\is_string($key) && preg_match(self::NON_TEXT_CONFIG_KEY_PATTERN, $key) === 1) {
                continue;
            }

            if (\is_array($value)) {
                $this->collectConfigText($value, $texts, \is_string($key) ? $key : $parentKey);
            } elseif (\is_string($value) && $this->looksLikeText($value, \is_string($key) ? $key : $parentKey)) {
                $texts[] = trim($value);
            }
        }
    }

    private function looksLikeText(string $value, ?string $key): bool
    {
        $value = trim($value);
        if ($value === '' || preg_match('~^(https?:|//|/|#|rgba?\()~i', $value) === 1 || Uuid::isValid($value)) {
            return false;
        }

        $plain = trim(strip_tags($value));
        if ($plain === '') {
            return false;
        }

        // Markup is text by definition, as are values of known text keys.
        if ($plain !== $value || ($key !== null && \in_array($key, self::TEXT_CONFIG_KEYS, true))) {
            return true;
        }

        // Single tokens (identifiers, e-mail addresses, paths) and CSS-like
        // values such as "0 auto 20px" are settings, not text.
        if (preg_match('~^[\w.@+\-:/]+$~', $plain) === 1 || preg_match('~^[\d.\s]+(px|rem|em|%|vh|vw|s|ms)?$~i', $plain) === 1) {
            return false;
        }

        return preg_match_all('/[\p{L}\p{N}]+/u', $plain) >= 2;
    }

    /**
     * Merge the category's slot overrides along the language inheritance chain,
     * as Shopware 6.7's CategoryRoute does (6.6 only uses the translated value).
     *
     * @return array<string, mixed>|null
     */
    private function mergeCategorySlotConfig(CategoryEntity $category, SalesChannelContext $salesChannelContext): ?array
    {
        $translated = $category->getTranslation('slotConfig');
        $translated = \is_array($translated) ? $translated : null;

        $translations = $category->getTranslations();
        $languageChain = $salesChannelContext->getContext()->getLanguageIdChain();
        if ($translations === null || \count($languageChain) <= 1) {
            return $translated;
        }

        $merged = [];
        foreach (array_reverse(array_unique($languageChain)) as $languageId) {
            foreach ($translations as $translation) {
                if ($translation->getLanguageId() === $languageId && \is_array($translation->getSlotConfig())) {
                    $merged = array_merge($merged, $translation->getSlotConfig());
                }
            }
        }

        return $merged !== [] ? $merged : $translated;
    }

    private function getSalesChannelContext(string $salesChannelId, string $languageId, string $currencyId): ?SalesChannelContext
    {
        $key = $salesChannelId . '|' . $languageId . '|' . $currencyId;
        if (\array_key_exists($key, $this->salesChannelContexts)) {
            return $this->salesChannelContexts[$key];
        }

        $options = [SalesChannelContextService::LANGUAGE_ID => $languageId];
        if ($currencyId !== '') {
            $options[SalesChannelContextService::CURRENCY_ID] = $currencyId;
        }

        try {
            $salesChannelContext = $this->salesChannelContextFactory->create(Uuid::randomHex(), $salesChannelId, $options);
        } catch (\Throwable $e) {
            $this->logger->warning('[Emporiqa] Could not create a storefront context to resolve CMS content, using stored slot content instead.', [
                'salesChannelId' => $salesChannelId,
                'languageId' => $languageId,
                'error' => $e->getMessage(),
            ]);
            $salesChannelContext = null;
        }

        return $this->salesChannelContexts[$key] = $salesChannelContext;
    }

    private function logFailure(string $entityLabel, string $entityId, \Throwable $e): void
    {
        $this->logger->warning(sprintf('[Emporiqa] Could not resolve CMS content for %s, using stored slot content instead.', $entityLabel), [
            'id' => $entityId,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }
}
