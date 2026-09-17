<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Tests\Support;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

trait EntityCollectionHelper
{
    /**
     * Collection of (mock) entities keyed by position, so mocks need no getUniqueIdentifier().
     * Null entries are skipped, which lets callers pass a nullable "first" entity.
     */
    private static function entityCollection(mixed ...$entities): EntityCollection
    {
        $collection = new EntityCollection();
        $index = 0;
        foreach ($entities as $entity) {
            if ($entity === null) {
                continue;
            }
            $collection->set((string) $index++, $entity);
        }

        return $collection;
    }
}
