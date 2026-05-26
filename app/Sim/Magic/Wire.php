<?php

namespace App\Sim\Magic;

/**
 * A directed connection carrying current from one node's output to another's input. The {@see Spell}
 * validates that the source node's output port type is one the destination node accepts.
 */
final readonly class Wire
{
    public function __construct(
        public int $from,
        public int $to,
    ) {}
}
