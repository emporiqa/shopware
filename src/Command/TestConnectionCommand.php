<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Command;

use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\ProductFormatterInterface;
use Emporiqa\ShopwarePlugin\Service\SyncServiceInterface;
use Emporiqa\ShopwarePlugin\Service\WebhookClientInterface;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'emporiqa:test-connection', description: 'Test connection to Emporiqa webhook endpoint')]
class TestConnectionCommand extends Command
{
    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly WebhookClientInterface $webhookClient,
        private readonly ConfigServiceInterface $configService,
        private readonly SyncServiceInterface $syncService,
        private readonly ProductFormatterInterface $productFormatter,
        private readonly EntityRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing Emporiqa Connection');

        $events = $this->buildTestEvents($io);
        $result = $this->webhookClient->testConnection($events);

        if ($result['success']) {
            $io->success($result['message']);
        } else {
            $io->error($result['message']);
        }

        if (isset($result['dry_run'])) {
            $this->displayDryRunResults($io, $result['dry_run']);
        }

        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function buildTestEvents(SymfonyStyle $io): array
    {
        if (!$this->configService->isConfigured()) {
            return [];
        }

        try {
            $channelContexts = $this->syncService->buildChannelContexts();
            if (empty($channelContexts)) {
                $io->warning('No sales channels or languages resolved, nothing will be synced. Check the Synced sales channels and Enabled languages settings.');
                return [];
            }

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsFilter('parentId', null));
            $criteria->addAssociation('categories.translations');
            $criteria->addAssociation('manufacturer.translations');
            $criteria->addAssociation('media.media');
            $criteria->addAssociation('cover.media');
            $criteria->addAssociation('seoUrls');
            $criteria->addAssociation('translations');
            $criteria->addAssociation('properties.group.translations');
            $criteria->addAssociation('properties.translations');
            $criteria->addAssociation('visibilities');
            $criteria->setLimit(5);

            $childrenCriteria = $criteria->getAssociation('children');
            $childrenCriteria->setLimit(5);
            $childrenCriteria->addAssociation('options.group.translations');
            $childrenCriteria->addAssociation('options.translations');
            $childrenCriteria->addAssociation('media.media');
            $childrenCriteria->addAssociation('cover.media');
            $childrenCriteria->addAssociation('seoUrls');
            $childrenCriteria->addAssociation('translations');

            $context = Context::createCLIContext();
            $products = $this->productRepository->search($criteria, $context)->getEntities();

            $product = null;
            foreach ($products as $candidate) {
                if ($candidate->getChildren() && $candidate->getChildren()->count() > 0) {
                    $product = $candidate;
                    break;
                }
            }

            if ($product === null) {
                $product = $products->first();
            }

            if (!$product instanceof ProductEntity) {
                $io->note('No active products found. Using default test payload.');
                return [];
            }

            $io->text('Using product: ' . ($product->getName() ?? $product->getProductNumber()));

            $formatted = $this->productFormatter->formatProduct($product, $channelContexts);

            return array_map(
                fn (array $item) => ['type' => 'product.created', 'data' => $item],
                $formatted,
            );
        } catch (\Throwable $e) {
            $io->note('Could not load real product: ' . $e->getMessage() . '. Using default test payload.');
            return [];
        }
    }

    /**
     * @param array<string, mixed> $dryRun
     */
    private function displayDryRunResults(SymfonyStyle $io, array $dryRun): void
    {
        $io->section('Dry Run Results');

        // Success: {events: [{type, sku, identification_number, languages_detected, channels_detected, fields, warnings, ...}]}
        $events = $dryRun['events'] ?? [];
        if (!empty($events)) {
            if (isset($dryRun['events_validated'])) {
                $io->text(sprintf('Events validated: <info>%d</info>', $dryRun['events_validated']));
                $io->newLine();
            }

            foreach ($events as $i => $event) {
                $io->text(sprintf(
                    '<info>Event %d:</info> %s | SKU: %s%s',
                    $i + 1,
                    $event['identification_number'] ?? 'N/A',
                    $event['sku'] ?? 'N/A',
                    ($event['is_parent'] ?? false) ? ' (parent)' : (isset($event['parent_sku']) && $event['parent_sku'] ? ' (variant)' : ''),
                ));

                $languages = $event['languages_detected'] ?? [];
                $channels = $event['channels_detected'] ?? [];
                if (!empty($languages)) {
                    $io->text('  Languages: ' . implode(', ', $languages));
                }
                if (!empty($channels)) {
                    $io->text('  Channels: ' . implode(', ', array_map(fn ($c) => $c ?: '(store-wide)', $channels)));
                }

                $fields = $event['fields'] ?? [];
                if (!empty($fields)) {
                    $rows = [];
                    foreach ($fields as $fieldName => $status) {
                        $icon = $status ? "\u{2713}" : "\u{2717}";
                        $rows[] = [$fieldName, $status ? "<info>$icon</info>" : "<error>$icon</error>"];
                    }
                    $io->table(['Field', 'Status'], $rows);
                }

                $warnings = $event['warnings'] ?? [];
                if (!empty($warnings)) {
                    $io->warning('Warnings:');
                    foreach ($warnings as $warning) {
                        $io->text('  - ' . $warning);
                    }
                } else {
                    $io->text('  <info>No warnings</info>');
                }

                $io->newLine();
            }

            return;
        }

        // Error response: {error: "...", details: [{event_index, event_type, errors: [{type, loc, msg}]}]}
        $details = $dryRun['details'] ?? [];
        if (!empty($details)) {
            foreach ($details as $detail) {
                $io->text(sprintf(
                    '<comment>Event %d</comment> (%s):',
                    $detail['event_index'] ?? '?',
                    $detail['event_type'] ?? 'unknown',
                ));

                $errors = $detail['errors'] ?? [];
                foreach ($errors as $err) {
                    $field = \is_array($err['loc'] ?? null) ? implode('.', $err['loc']) : ($err['loc'] ?? '?');
                    $io->text(sprintf('  <error>%s</error>: %s', $field, $err['msg'] ?? 'Unknown error'));
                }

                $io->newLine();
            }

            return;
        }

        $io->text('No event details returned.');
    }
}
