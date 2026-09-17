<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Subscriber;

use Doctrine\DBAL\Connection;
use Emporiqa\ShopwarePlugin\MessageQueue\Message\PageResyncMessage;
use Emporiqa\ShopwarePlugin\Service\ConfigServiceInterface;
use Emporiqa\ShopwarePlugin\Service\PageSyncRegistry;
use Emporiqa\ShopwarePlugin\Subscriber\SeoUrlSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Seo\Event\SeoUrlUpdateEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class SeoUrlSubscriberTest extends TestCase
{
    private ConfigServiceInterface&MockObject $config;
    private PageSyncRegistry $registry;
    private Connection&MockObject $connection;
    private MessageBusInterface&MockObject $messageBus;
    private SeoUrlSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigServiceInterface::class);
        $this->config->method('isConfigured')->willReturn(true);
        $this->config->method('isSyncPagesEnabled')->willReturn(true);
        $this->registry = new PageSyncRegistry();
        $this->connection = $this->createMock(Connection::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->subscriber = new SeoUrlSubscriber(
            $this->config,
            $this->registry,
            $this->connection,
            $this->messageBus,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testSubscribesToPersisterEventAndDalWrites(): void
    {
        $events = SeoUrlSubscriber::getSubscribedEvents();

        $this->assertSame('onSeoUrlUpdate', $events[SeoUrlUpdateEvent::class]);
        $this->assertSame('onSeoUrlWritten', $events['seo_url.written']);
    }

    public function testGeneratedUrlsQueuePagesByTechnicalPath(): void
    {
        $landingPageId = Uuid::randomHex();
        $categoryId = Uuid::randomHex();
        $productId = Uuid::randomHex();
        $deletedId = Uuid::randomHex();

        $this->expectPageSync([$landingPageId], [$categoryId]);

        $this->subscriber->onSeoUrlUpdate(new SeoUrlUpdateEvent([
            ['foreignKey' => $landingPageId, 'pathInfo' => '/landingPage/' . $landingPageId, 'seoPathInfo' => 'faq'],
            ['foreignKey' => $categoryId, 'pathInfo' => '/navigation/' . $categoryId, 'seoPathInfo' => 'service/', 'isCanonical' => true],
            ['foreignKey' => $productId, 'pathInfo' => '/detail/' . $productId, 'seoPathInfo' => 'product'],
            ['foreignKey' => $deletedId, 'pathInfo' => '/landingPage/' . $deletedId, 'seoPathInfo' => 'old', 'isDeleted' => true],
            ['foreignKey' => $categoryId, 'pathInfo' => '/navigation/' . $categoryId, 'seoPathInfo' => 'de/service/'],
        ]));
    }

    public function testGeneratedUrlsSkipPagesAlreadyClaimedThisRequest(): void
    {
        $landingPageId = Uuid::randomHex();
        $this->registry->claim([$landingPageId]);
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onSeoUrlUpdate(new SeoUrlUpdateEvent([
            ['foreignKey' => $landingPageId, 'pathInfo' => '/landingPage/' . $landingPageId],
        ]));
    }

    public function testDalWritesResolveRoutesFromTheDatabase(): void
    {
        $seoUrlId = Uuid::randomHex();
        $landingPageId = Uuid::randomHex();

        $this->connection->method('fetchAllAssociative')->willReturn([
            ['route_name' => 'frontend.landing.page', 'foreign_key' => $landingPageId],
        ]);
        $this->expectPageSync([$landingPageId], []);

        $this->subscriber->onSeoUrlWritten(new EntityWrittenEvent(
            'seo_url',
            [new EntityWriteResult($seoUrlId, ['seoPathInfo' => 'new-path'], 'seo_url', EntityWriteResult::OPERATION_UPDATE)],
            Context::createDefaultContext(),
        ));
    }

    public function testDalDeletesAreIgnored(): void
    {
        $this->connection->expects($this->never())->method('fetchAllAssociative');
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->subscriber->onSeoUrlWritten(new EntityDeletedEvent(
            'seo_url',
            [new EntityWriteResult(Uuid::randomHex(), [], 'seo_url', EntityWriteResult::OPERATION_DELETE)],
            Context::createDefaultContext(),
        ));
    }

    public function testSkipsWhenPageSyncDisabled(): void
    {
        $config = $this->createMock(ConfigServiceInterface::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isSyncPagesEnabled')->willReturn(false);
        $this->messageBus->expects($this->never())->method('dispatch');

        $subscriber = new SeoUrlSubscriber($config, $this->registry, $this->connection, $this->messageBus, $this->createMock(LoggerInterface::class));
        $id = Uuid::randomHex();
        $subscriber->onSeoUrlUpdate(new SeoUrlUpdateEvent([['foreignKey' => $id, 'pathInfo' => '/landingPage/' . $id]]));
    }

    /**
     * @param list<string> $landingPageIds
     * @param list<string> $categoryIds
     */
    private function expectPageSync(array $landingPageIds, array $categoryIds): void
    {
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(fn ($message) => $message instanceof PageResyncMessage
                && $message->getLandingPageIds() === $landingPageIds
                && $message->getCategoryIds() === $categoryIds
                && $message->getCreatedIds() === []))
            ->willReturn(new Envelope(new \stdClass()));
    }
}
