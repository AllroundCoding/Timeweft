<?php

namespace App\Sim\Magic;

/**
 * A kind of magic node in the palette — its typed ports and what it costs to include. The catalog of these
 * lives in {@see NodeTypeRegistry}, exactly as traits and goods have their registries. A {@see MagicNode}
 * is an instance of one of these placed in a spell.
 */
final readonly class NodeType
{
    /**
     * @param  list<PortType>  $inputs  the port types this node accepts (empty for a source)
     * @param  ?PortType  $output  the port type it emits (null for a sink — a terminal effect)
     */
    public function __construct(
        public string $name,
        public NodeKind $kind,
        public array $inputs,
        public ?PortType $output,
        /** Fixed magical overhead to run the node, beyond the current that flows through it. */
        public float $baseCost = 0.0,
        /** Whether the node's instance magnitude multiplies the incoming current (the amplify behaviour). */
        public bool $amplifies = false,
    ) {}

    public function accepts(PortType $incoming): bool
    {
        return in_array($incoming, $this->inputs, true);
    }
}
