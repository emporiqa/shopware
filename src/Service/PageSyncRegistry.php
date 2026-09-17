<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped record of the pages already queued for a sync, shared by the
 * subscribers so one write batch never queues the same page twice.
 */
class PageSyncRegistry implements ResetInterface
{
    /** @var array<string, true> */
    private array $queued = [];

    /**
     * Marks the ids as queued and returns the ones that were not queued before.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function claim(array $ids): array
    {
        $fresh = [];
        foreach ($ids as $id) {
            if (isset($this->queued[$id])) {
                continue;
            }
            $this->queued[$id] = true;
            $fresh[] = $id;
        }

        return $fresh;
    }

    public function reset(): void
    {
        $this->queued = [];
    }
}
