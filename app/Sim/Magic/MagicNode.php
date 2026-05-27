<?php

namespace App\Sim\Magic;

/**
 * One node placed in a {@see Spell} — an instance of a {@see NodeType} with its tunable parameters. The
 * meaning of {@see $magnitude} depends on the node's kind: a source's raw draw, an amplify's factor;
 * other nodes ignore it. {@see $school} is set by sources and school-convert transforms.
 */
final readonly class MagicNode
{
    public function __construct(
        public int $id,
        public NodeType $type,
        public float $magnitude = 1.0,
        public ?MagicSchool $school = null,
    ) {}
}
