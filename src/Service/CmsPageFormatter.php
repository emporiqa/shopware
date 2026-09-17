<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\LandingPage\LandingPageEntity;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlCollection;

class CmsPageFormatter implements CmsPageFormatterInterface
{
    use TranslationResolverTrait;

    public function __construct(
        private readonly ?CmsContentResolverInterface $contentResolver = null,
    ) {
    }

    /**
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @return array<string, mixed>|null
     */
    public function formatLandingPage(
        LandingPageEntity $landingPage,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): ?array {
        // The storefront only serves landing pages assigned to the sales channel.
        // When the association was not loaded no restriction is applied.
        $assignedChannels = $landingPage->getSalesChannels();
        $assignedIds = $assignedChannels !== null ? array_flip($assignedChannels->getIds()) : null;

        $targets = $this->resolveTargets(
            $channelContexts,
            $landingPage->getSeoUrls(),
            static fn (array $ctx): bool => $assignedIds === null || isset($assignedIds[$ctx['salesChannelId']]),
            '/landingPage/' . $landingPage->getId(),
        );

        $titles = [];
        $contents = [];
        $links = [];

        foreach ($targets as ['channelKey' => $channelKey, 'context' => $ctx, 'link' => $link]) {
            $langCode = $ctx['languageCode'];
            $languageId = $ctx['languageId'];

            $titles[$channelKey][$langCode] = $this->getTranslatedTitle($landingPage, $languageId);

            $content = $this->contentResolver?->resolveLandingPageContent(
                $landingPage->getId(),
                $ctx['salesChannelId'],
                $languageId,
                $ctx['currencyId'] ?? '',
            );
            if ($content === null || $content === '') {
                $content = $this->extractStoredContent($landingPage->getCmsPage(), $landingPage, $languageId);
            }
            // Pages built purely from image/banner blocks resolve to no
            // extractable text, fall back to the meta description so
            // they don't sync with empty content.
            if ($content === '') {
                $content = $this->getTranslatedString($landingPage, 'metaDescription', $languageId);
            }

            $contents[$channelKey][$langCode] = $content;
            $links[$channelKey][$langCode] = $link;
        }

        return $this->buildPayload('page-' . $landingPage->getId(), $titles, $contents, $links, $syncSessionId);
    }

    public function formatShopPage(
        CategoryEntity $category,
        array $channelContexts,
        ?string $syncSessionId = null,
    ): ?array {
        // Tree roots (navigation, footer, service) are not pages.
        if ($category->getParentId() === null) {
            return null;
        }

        $targets = $this->resolveTargets(
            $channelContexts,
            $category->getSeoUrls(),
            static fn (array $ctx): bool => self::isCategoryInChannel($category, $ctx),
            '/navigation/' . $category->getId(),
        );

        $titles = [];
        $contents = [];
        $links = [];

        foreach ($targets as ['channelKey' => $channelKey, 'context' => $ctx, 'link' => $link]) {
            $langCode = $ctx['languageCode'];
            $languageId = $ctx['languageId'];

            $titles[$channelKey][$langCode] = $this->getTranslatedTitle($category, $languageId);

            $content = $this->contentResolver?->resolveCategoryContent(
                $category->getId(),
                $ctx['salesChannelId'],
                $languageId,
                $ctx['currencyId'] ?? '',
            );
            if ($content === null || $content === '') {
                $content = $this->extractStoredContent($category->getCmsPage(), $category, $languageId);
            }
            if ($content === '') {
                $content = $this->getTranslatedString($category, 'description', $languageId);
            }

            $contents[$channelKey][$langCode] = $content;
            $links[$channelKey][$langCode] = $link;
        }

        return $this->buildPayload('page-' . $category->getId(), $titles, $contents, $links, $syncSessionId);
    }

    public function formatPageDelete(string $pageId): array
    {
        return [
            ['identification_number' => 'page-' . $pageId],
        ];
    }

    /**
     * @param array<string, array<string, string>> $titles
     * @param array<string, array<string, string>> $contents
     * @param array<string, array<string, string>> $links
     * @return array<string, mixed>|null
     */
    private function buildPayload(string $identificationNumber, array $titles, array $contents, array $links, ?string $syncSessionId): ?array
    {
        if ($titles === []) {
            return null;
        }

        $data = [
            'identification_number' => $identificationNumber,
            'channels' => array_map('strval', array_keys($titles)),
            'titles' => $titles,
            'contents' => $contents,
            'links' => $links,
        ];

        if ($syncSessionId !== null) {
            $data['sync_session_id'] = $syncSessionId;
        }

        return $data;
    }

    /**
     * One target per Emporiqa channel and language in which the page is reachable.
     * The link is the canonical SEO URL of that sales channel and language when one
     * exists, otherwise Shopware's technical route, which resolves in the storefront
     * and redirects to the SEO URL once the indexer has generated it. Contexts with
     * an SEO URL win over those without, and HTTPS domains over HTTP ones.
     *
     * @param array<string, array<int, array<string, string>>> $channelContexts
     * @param callable(array<string, string>): bool $isReachable
     * @return list<array{channelKey: string, context: array<string, string>, link: string}>
     */
    private function resolveTargets(array $channelContexts, ?SeoUrlCollection $seoUrls, callable $isReachable, string $technicalPath): array
    {
        $seoPaths = [];
        foreach ($seoUrls ?? [] as $seoUrl) {
            $salesChannelId = $seoUrl->getSalesChannelId();
            if ($salesChannelId === null || $seoUrl->getIsDeleted() || !$seoUrl->getIsCanonical()) {
                continue;
            }
            $seoPaths[$salesChannelId . '|' . $seoUrl->getLanguageId()] ??= $seoUrl->getSeoPathInfo();
        }

        $targets = [];
        $scores = [];
        foreach ($channelContexts as $channelKey => $ctxList) {
            foreach ($ctxList as $ctx) {
                if (!$isReachable($ctx)) {
                    continue;
                }

                $seoPath = $seoPaths[$ctx['salesChannelId'] . '|' . $ctx['languageId']] ?? null;
                $score = ($seoPath !== null ? 2 : 0) + (str_starts_with($ctx['domainUrl'], 'https://') ? 1 : 0);

                $targetKey = $channelKey . '|' . $ctx['languageCode'];
                if (isset($scores[$targetKey]) && $scores[$targetKey] >= $score) {
                    continue;
                }

                $scores[$targetKey] = $score;
                $targets[$targetKey] = [
                    'channelKey' => (string) $channelKey,
                    'context' => $ctx,
                    'link' => rtrim($ctx['domainUrl'], '/') . '/' . ltrim($seoPath ?? $technicalPath, '/'),
                ];
            }
        }

        return array_values($targets);
    }

    /**
     * A category is a page of a sales channel when it sits in the channel's
     * navigation, footer or service tree. Contexts without tree information
     * (e.g. altered by a PreSyncEvent listener) count the category as reachable.
     *
     * @param array<string, string> $ctx
     */
    private static function isCategoryInChannel(CategoryEntity $category, array $ctx): bool
    {
        if (!\array_key_exists('navigationCategoryId', $ctx)) {
            return true;
        }

        $path = $category->getPath() ?? '';
        foreach (['navigationCategoryId', 'footerCategoryId', 'serviceCategoryId'] as $rootField) {
            $rootId = $ctx[$rootField] ?? '';
            if ($rootId !== '' && str_contains($path, '|' . $rootId . '|')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Content read straight from the stored slot configs, used when the storefront
     * content resolver is unavailable or fails.
     */
    private function extractStoredContent(?CmsPageEntity $cmsPage, object $entity, string $languageId): string
    {
        if ($cmsPage === null) {
            return '';
        }

        return $this->extractCmsPageContent($cmsPage, $languageId, $this->getTranslatedSlotConfig($entity, $languageId));
    }

    /**
     * @param array<string, mixed> $slotConfigOverride slotId => slot config; the page's per-language slotConfig override
     */
    private function extractCmsPageContent(CmsPageEntity $cmsPage, string $languageId = '', array $slotConfigOverride = []): string
    {
        $sections = $cmsPage->getSections();
        if ($sections === null || $sections->count() === 0) {
            return '';
        }

        $texts = [];

        foreach ($sections as $section) {
            $blocks = $section->getBlocks();
            if ($blocks === null) {
                continue;
            }

            foreach ($blocks as $block) {
                $slots = $block->getSlots();
                if ($slots === null) {
                    continue;
                }

                foreach ($slots as $slot) {
                    $content = '';

                    // The page's own per-language slotConfig override takes precedence
                    // over the shared CMS layout, this is where landing page and
                    // category content typed per language actually lives.
                    if ($slotConfigOverride !== []) {
                        $override = $slotConfigOverride[$slot->getId()] ?? null;
                        if (\is_array($override) && isset($override['content']['value']) && \is_string($override['content']['value'])) {
                            $content = $override['content']['value'];
                        }
                    }

                    // Try language-specific config from the shared layout's slot translation
                    if ($content === '' && $languageId !== '') {
                        $content = $this->getSlotConfigContent($slot, $languageId);
                    }

                    // Fall back to default config
                    if ($content === '') {
                        $config = $slot->getConfig();
                        if (\is_array($config) && isset($config['content']['value']) && \is_string($config['content']['value'])) {
                            $content = $config['content']['value'];
                        }
                    }

                    // Fall back to resolved slot data
                    if ($content === '') {
                        $slotData = $slot->getData();
                        if ($slotData !== null) {
                            $content = $this->extractSlotContent($slotData);
                        }
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
     * Resolve a page's per-language slotConfig override (landing page / category),
     * keyed by slot id. Returns an empty array when no translation matches.
     *
     * @return array<string, mixed>
     */
    private function getTranslatedSlotConfig(object $entity, string $languageId): array
    {
        $translation = $this->findTranslationForLanguage($entity, $languageId);
        if ($translation === null || !method_exists($translation, 'getSlotConfig')) {
            return [];
        }

        $config = $translation->getSlotConfig();

        return \is_array($config) ? $config : [];
    }

    private function getSlotConfigContent(object $slot, string $languageId): string
    {
        $translation = $this->findTranslationForLanguage($slot, $languageId);
        if ($translation === null || !method_exists($translation, 'getConfig')) {
            return '';
        }

        $config = $translation->getConfig();
        if (\is_array($config) && isset($config['content']['value']) && \is_string($config['content']['value'])) {
            return $config['content']['value'];
        }

        return '';
    }

    /**
     * Find the translation entity matching a language id on a translatable entity
     * (landing page, category, or CMS slot). Returns null when none matches.
     */
    private function findTranslationForLanguage(object $entity, string $languageId): ?object
    {
        if ($languageId === '' || !method_exists($entity, 'getTranslations')) {
            return null;
        }

        $translations = $entity->getTranslations();
        if ($translations === null) {
            return null;
        }

        foreach ($translations as $translation) {
            if (method_exists($translation, 'getLanguageId') && $translation->getLanguageId() === $languageId) {
                return $translation;
            }
        }

        return null;
    }

    private function extractSlotContent(object $data): string
    {
        if (method_exists($data, 'getContent')) {
            $content = $data->getContent();
            if (\is_string($content) && $content !== '') {
                return $content;
            }
        }

        if (method_exists($data, 'getText')) {
            $text = $data->getText();
            if (\is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }
}
