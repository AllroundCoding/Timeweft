<?php

namespace App\Sim\World;

use App\Sim\Economy\Good;
use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\Recipe;
use App\Sim\Economy\RecipeBook;

/**
 * A region *type* — the common traits a biome logically shares (design doc 05). Rather than
 * hand-authoring each named region, a settlement is founded from an archetype: a desert breeds
 * endurance and a feast-or-famine food supply; a temperate sownland breeds abundance and a milder
 * swing. The archetype is the reusable guideline; a {@see RegionProfile} is a concrete region built
 * from it. This is the seam toward lore-as-data — an imported world (TWT-119) is a set of these.
 *
 * The contrast between archetypes is what makes settlements *specialize*: each produces a different
 * basket of foodstuffs and is rich in different trade goods, so a desert oasis and a fertile lowland
 * have distinct economies — the ground inter-settlement trade stands on.
 */
final class RegionArchetype
{
    /**
     * @param  array<string,float>  $traitModifiers  additive nudges to numeric traits a body adapts here
     * @param  array<string,list<string>>  $categoricalOptions  trait => allowed values for this biome
     * @param  array<string,float>  $yieldBySeason  food-yield multiplier per season (1.0 = baseline)
     * @param  array<string,float>  $basket  food good => per-adult daily yield (the diet this land grows)
     * @param  list<string>  $resources  the region's notable trade goods (its specialties)
     * @param  list<Good>  $goods  goods this region adds to the world catalog
     * @param  list<Recipe>  $recipes  meals cookable from this region's basket
     */
    public function __construct(
        public readonly string $regionName,
        public readonly string $cultureName,
        public readonly array $traitModifiers,
        public readonly array $categoricalOptions,
        public readonly array $yieldBySeason,
        public readonly array $basket,
        public readonly array $resources,
        public readonly array $goods,
        public readonly array $recipes,
    ) {}

    /**
     * The desert (Tharados): two seasons drive a feast-or-famine supply — a brief, fertile Oasis and
     * the long, lean Sandstorm. Endurance and desert-attuned senses; rich in gems, spices, and salt.
     * Its goods, recipes, basket and nudges mirror the hand-seeded Tharadi factories exactly.
     */
    public static function desert(): self
    {
        return new self(
            regionName: 'Tharados',
            cultureName: 'Tharadi',
            traitModifiers: [
                'constitution' => 8.0,  // resilient desert dwellers
                'senses' => 4.0,        // attuned to the open desert
            ],
            categoricalOptions: [
                'furColor' => ['sandy', 'golden', 'pale tan', 'dust-grey', 'ochre'],
            ],
            yieldBySeason: [
                'Oasis' => 1.5,      // the brief, fertile season
                'Sandstorm' => 0.5,  // the long, lean months
            ],
            basket: ['grain' => 3.0, 'dates' => 1.5, 'goat meat' => 1.5],
            resources: ['gems', 'spices', 'silk', 'salt', 'dates', 'olive oil'],
            goods: array_values(GoodRegistry::tharados()->all()),
            recipes: RecipeBook::tharados()->all(),
        );
    }

    /**
     * The temperate sownland (Aetheria): fertile plains under a milder swing — abundant grain, orchard
     * fruit, and herbs, buffered against the lean season. No strong physical adaptation (the temperate
     * default). Rich in grain, wool, timber, and metals. Scarcity ~0.40 / volatility ~0.43 — far gentler
     * than the desert's 0.75 / 0.50, so cultural materialism predicts a looser, more individual culture.
     *
     * Adds only its distinctive goods (fruit, herbs); grain and water come from the shared catalog.
     */
    public static function sownland(): self
    {
        return new self(
            regionName: 'Aetheria',
            cultureName: 'Aetherian',
            traitModifiers: [], // the temperate default — no harsh selection pressure
            categoricalOptions: [
                'furColor' => ['red', 'brown', 'gold', 'white-bellied', 'black-tipped'],
            ],
            yieldBySeason: [
                'Oasis' => 2.0,      // the rich growing season of plains and orchards
                'Sandstorm' => 0.8,  // a milder lean season, buffered by storage and trade
            ],
            basket: ['grain' => 4.0, 'fruit' => 2.0, 'herbs' => 0.5],
            resources: ['grain', 'fruit', 'herbs', 'wool', 'horses', 'timber', 'iron', 'silver', 'gold', 'gems', 'enchanted goods'],
            goods: [
                new Good('fruit', nutrition: 55.0, value: 25.0, perishability: 50.0),
                new Good('herbs', nutrition: 20.0, value: 35.0, perishability: 60.0),
            ],
            recipes: [
                new Recipe('fruited grain pottage', ['grain' => 2.0, 'fruit' => 1.0]),
                new Recipe('herb-and-grain loaf', ['grain' => 2.0, 'herbs' => 0.5]),
            ],
        );
    }

    /** Build a concrete region profile from this archetype's guidelines. */
    public function toRegionProfile(): RegionProfile
    {
        return new RegionProfile(
            name: $this->regionName,
            traitModifiers: $this->traitModifiers,
            categoricalOptions: $this->categoricalOptions,
            yieldBySeason: $this->yieldBySeason,
            basket: $this->basket,
            resources: $this->resources,
        );
    }
}
