<?php

declare(strict_types=1);

namespace Jadob\EventSourcing\EventStore;

use function json_decode;
use function json_encode;

/**
 * @author pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class PayloadSerializer
{
    /**
     * @param array $payload
     * @return string
     */
    public function serialize(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $serialized
     * @return array
     */
    public function deserialize(string $serialized): array
    {
        return json_decode($serialized, true);
    }
}