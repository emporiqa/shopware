<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Event\NestedEvent;

trait EntityWriteEventTrait
{
    /**
     * Single-column primary key of a write result, null for composite keys
     * (translations, mapping tables).
     *
     * @param array<string, mixed>|string $primaryKey
     */
    private static function primaryKeyId(array|string $primaryKey): ?string
    {
        return \is_string($primaryKey) ? $primaryKey : null;
    }

    /**
     * The nested event when it is a (non-delete) write of the given entity, else null.
     */
    private static function writtenEventOf(NestedEvent $event, string $entityName): ?EntityWrittenEvent
    {
        if (!$event instanceof EntityWrittenEvent || $event instanceof EntityDeletedEvent) {
            return null;
        }

        return $event->getEntityName() === $entityName ? $event : null;
    }
}
