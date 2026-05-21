<?php

namespace App\Sim\Culture;

/**
 * A faith expressed as a weighting (0..100) over Haidt's six Moral Foundations — care, fairness,
 * loyalty, authority, sanctity, liberty. Its tenets and taboos are simply the foundations it
 * weights most heavily, and `binding` (from the culture's piety) is how strongly those tenets
 * actually steer behavior. Generated from the culture vector (design doc 11): a collectivist,
 * hierarchical, traditional, pious culture grows a binding faith (loyalty/authority/sanctity);
 * an individualist, egalitarian one leans on the individualizing foundations (care/fairness/liberty).
 *
 * This is the structured source the faith-tenet disposition modifiers read from; on its own it is
 * inert (no behavior change yet).
 */
final class Faith
{
    /** @var list<string> the six foundations, in display order */
    public const FOUNDATIONS = ['care', 'fairness', 'loyalty', 'authority', 'sanctity', 'liberty'];

    public function __construct(
        public readonly string $name,
        public readonly float $care,
        public readonly float $fairness,
        public readonly float $loyalty,
        public readonly float $authority,
        public readonly float $sanctity,
        public readonly float $liberty,
        public readonly float $binding,
    ) {}

    public static function fromCulture(string $name, Culture $culture): self
    {
        $clamp = static fn (float $v): float => max(0.0, min(100.0, $v));

        return new self(
            name: $name,
            care: $clamp(50.0 + ($culture->collectivism - 50.0) * 0.3),     // compassion for the in-group
            fairness: $clamp(80.0 - $culture->hierarchy * 0.5),             // egalitarian → fairness
            loyalty: $clamp(25.0 + $culture->collectivism * 0.65),          // in-group binding
            authority: $clamp(25.0 + $culture->hierarchy * 0.65),           // respect for rank
            sanctity: $clamp(($culture->tradition + $culture->piety) / 2.0), // purity / the sacred
            liberty: $clamp(90.0 - $culture->hierarchy * 0.4 - $culture->collectivism * 0.3), // anti-domination
            binding: max(0.0, min(1.0, $culture->piety / 100.0)),
        );
    }

    /** @return array<string,float> */
    public function vector(): array
    {
        return [
            'care' => $this->care,
            'fairness' => $this->fairness,
            'loyalty' => $this->loyalty,
            'authority' => $this->authority,
            'sanctity' => $this->sanctity,
            'liberty' => $this->liberty,
        ];
    }

    /**
     * The foundations this faith weights most heavily — its defining tenets and taboos.
     *
     * @return list<string>
     */
    public function tenets(int $top = 2): array
    {
        $vector = $this->vector();
        arsort($vector);

        return array_slice(array_keys($vector), 0, max(1, $top));
    }
}
