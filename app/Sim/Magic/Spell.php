<?php

namespace App\Sim\Magic;

/**
 * A spell: an immutable directed graph of {@see MagicNode}s wired source → transforms → sink. Validated on
 * construction — port types must be compatible, the graph acyclic, every node on a path from a source to
 * the single sink — so an invalid spell cannot exist (the evaluator may assume a well-formed graph). A
 * known or taught spell is path-dependent canon (skeleton); evaluating one is texture.
 */
final readonly class Spell
{
    /** @var array<int,MagicNode> node by id, for O(1) lookup */
    private array $byId;

    /**
     * @param  list<MagicNode>  $nodes
     * @param  list<Wire>  $wires
     *
     * @throws \InvalidArgumentException on a malformed graph (incompatible ports, a cycle, no/many sinks…)
     */
    public function __construct(
        public string $name,
        public array $nodes,
        public array $wires,
    ) {
        $byId = [];
        foreach ($nodes as $node) {
            if (isset($byId[$node->id])) {
                throw new \InvalidArgumentException("Duplicate node id {$node->id} in spell '{$name}'");
            }
            $byId[$node->id] = $node;
        }
        $this->byId = $byId;
        $this->validate();
    }

    public function node(int $id): MagicNode
    {
        return $this->byId[$id] ?? throw new \InvalidArgumentException("No node {$id} in spell '{$this->name}'");
    }

    /** The single terminal effect of the spell. */
    public function sink(): MagicNode
    {
        foreach ($this->nodes as $node) {
            if ($node->type->kind === NodeKind::Sink) {
                return $node;
            }
        }

        throw new \InvalidArgumentException("Spell '{$this->name}' has no sink"); // unreachable after validate()
    }

    /**
     * Node ids in evaluation order (sources first), ties broken by ascending id so the order — and thus the
     * cast — is identical on every run. Throws if the graph contains a cycle.
     *
     * @return list<int>
     */
    public function topologicalOrder(): array
    {
        $indegree = [];
        $adjacency = [];
        foreach ($this->nodes as $node) {
            $indegree[$node->id] = 0;
            $adjacency[$node->id] = [];
        }
        foreach ($this->wires as $wire) {
            $adjacency[$wire->from][] = $wire->to;
            $indegree[$wire->to]++;
        }

        $ready = [];
        foreach ($indegree as $id => $degree) {
            if ($degree === 0) {
                $ready[] = $id;
            }
        }
        sort($ready);

        $order = [];
        while ($ready !== []) {
            $id = (int) array_shift($ready);
            $order[] = $id;
            $released = false;
            foreach ($adjacency[$id] as $next) {
                if (--$indegree[$next] === 0) {
                    $ready[] = $next;
                    $released = true;
                }
            }
            if ($released) {
                sort($ready);
            }
        }

        if (count($order) !== count($this->nodes)) {
            throw new \InvalidArgumentException("Spell '{$this->name}' graph contains a cycle");
        }

        return $order;
    }

    /** @return list<int> the ids of nodes wired into the given node */
    public function inputsTo(int $id): array
    {
        $sources = [];
        foreach ($this->wires as $wire) {
            if ($wire->to === $id) {
                $sources[] = $wire->from;
            }
        }
        sort($sources);

        return $sources;
    }

    private function validate(): void
    {
        if ($this->nodes === []) {
            throw new \InvalidArgumentException("Spell '{$this->name}' has no nodes");
        }

        $sinks = array_filter($this->nodes, static fn (MagicNode $n): bool => $n->type->kind === NodeKind::Sink);
        if (count($sinks) !== 1) {
            throw new \InvalidArgumentException("Spell '{$this->name}' must have exactly one sink, found ".count($sinks));
        }

        $hasIncoming = [];
        $hasOutgoing = [];
        foreach ($this->wires as $wire) {
            if (! isset($this->byId[$wire->from]) || ! isset($this->byId[$wire->to])) {
                throw new \InvalidArgumentException("Spell '{$this->name}' wires an unknown node");
            }
            $from = $this->byId[$wire->from];
            $to = $this->byId[$wire->to];
            if ($from->type->output === null) {
                throw new \InvalidArgumentException("Node {$from->id} ({$from->type->name}) is a sink and cannot feed a wire");
            }
            if (! $to->type->accepts($from->type->output)) {
                throw new \InvalidArgumentException(
                    "Incompatible wire in '{$this->name}': {$from->type->name} emits {$from->type->output->value}, ".
                    "but {$to->type->name} does not accept it"
                );
            }
            $hasOutgoing[$wire->from] = true;
            $hasIncoming[$wire->to] = true;
        }

        foreach ($this->nodes as $node) {
            $incoming = isset($hasIncoming[$node->id]);
            $outgoing = isset($hasOutgoing[$node->id]);
            if ($node->type->kind === NodeKind::Source && $incoming) {
                throw new \InvalidArgumentException("Source {$node->id} ({$node->type->name}) cannot take an incoming wire");
            }
            if ($node->type->kind === NodeKind::Sink && $outgoing) {
                throw new \InvalidArgumentException("Sink {$node->id} ({$node->type->name}) cannot feed an outgoing wire");
            }
            if ($node->type->kind !== NodeKind::Source && ! $incoming) {
                throw new \InvalidArgumentException("Node {$node->id} ({$node->type->name}) has no input wired");
            }
            if ($node->type->kind !== NodeKind::Sink && ! $outgoing) {
                throw new \InvalidArgumentException("Node {$node->id} ({$node->type->name}) has no output wired");
            }
        }

        $this->topologicalOrder(); // throws on a cycle
    }
}
