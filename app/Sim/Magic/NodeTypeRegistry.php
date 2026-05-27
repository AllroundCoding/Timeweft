<?php

namespace App\Sim\Magic;

/**
 * The palette of magic node types (design doc 20) — the same registry pattern as traits and goods. A
 * spell is built from instances of these. {@see standard()} is the built-in catalog; a world may extend
 * or replace it (the generated palette — schools/pills from the thaumic field — is a later ticket).
 */
final class NodeTypeRegistry
{
    /** @var array<string,NodeType> */
    private array $types = [];

    /** @param list<NodeType> $types */
    public function __construct(array $types)
    {
        foreach ($types as $type) {
            $this->types[$type->name] = $type;
        }
    }

    /**
     * The built-in node palette: sources that draw raw current, transforms that shape and amplify it, and
     * sinks that spend it as a world-effect. Costs are overhead — the current itself is paid for at its
     * source and at any amplify (see {@see SpellEvaluator}).
     */
    public static function standard(): self
    {
        return new self([
            // Sources — draw raw Energy; their cost is the magnitude they pull from the supply.
            new NodeType('field-draw', NodeKind::Source, [], PortType::Energy),
            new NodeType('crystal', NodeKind::Source, [], PortType::Energy),
            new NodeType('caster-energy', NodeKind::Source, [], PortType::Energy),
            new NodeType('sacrifice', NodeKind::Source, [], PortType::Energy),
            new NodeType('divine-grace', NodeKind::Source, [], PortType::Energy),

            // Transforms — shape and amplify the current.
            new NodeType('amplify', NodeKind::Transform, [PortType::Energy], PortType::Energy, amplifies: true),
            new NodeType('shape', NodeKind::Transform, [PortType::Energy], PortType::Shaped, baseCost: 1.0),
            new NodeType('school-convert', NodeKind::Transform, [PortType::Shaped], PortType::Shaped, baseCost: 2.0),

            // Sinks — spend shaped current as a world-effect (terminal: no output port).
            new NodeType('heal', NodeKind::Sink, [PortType::Shaped], null, baseCost: 1.0),
            new NodeType('harm', NodeKind::Sink, [PortType::Shaped], null, baseCost: 1.0),
            new NodeType('ward', NodeKind::Sink, [PortType::Shaped], null, baseCost: 1.5),
            new NodeType('enchant', NodeKind::Sink, [PortType::Shaped], null, baseCost: 2.0),
            new NodeType('alter-environment', NodeKind::Sink, [PortType::Shaped], null, baseCost: 3.0),
        ]);
    }

    public function get(string $name): NodeType
    {
        return $this->types[$name] ?? throw new \InvalidArgumentException("Unknown magic node type: {$name}");
    }

    /** @return array<string,NodeType> */
    public function all(): array
    {
        return $this->types;
    }
}
