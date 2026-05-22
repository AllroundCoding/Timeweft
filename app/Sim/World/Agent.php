<?php

namespace App\Sim\World;

use App\Sim\Behavior\Activity;
use App\Sim\Economy\Stockpile;
use App\Sim\Time\TharadiCalendar;

final class Agent
{
    /** Current activity, set by the BehaviorEngine each tick. */
    public ?Activity $activity = null;

    public ?int $partnerId = null;

    /** Id of the chronicle pairing event that formed the current bond — the cause cited by any birth. */
    public ?int $pairingEventId = null;

    /** @var array<int> [motherId, fatherId] */
    public array $parentIds = [];

    public bool $alive = true;

    public ?int $deathTick = null;

    public ?int $lastBirthTick = null;

    /**
     * @param  array<string,float|string>  $traits
     * @param  array<string,Need>  $needs
     */
    public function __construct(
        public readonly int $id,
        public string $name,
        public readonly string $species,
        public readonly string $region,
        public readonly string $sex,        // 'f' | 'm'
        public readonly int $birthTick,
        public array $traits,
        public array $needs,
        public Stockpile $stockpile = new Stockpile,
    ) {}

    public function trait(string $key): float|string|null
    {
        return $this->traits[$key] ?? null;
    }

    public function ageInYears(int $nowTick): int
    {
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        $reference = $this->alive ? $nowTick : ($this->deathTick ?? $nowTick);

        return intdiv($reference - $this->birthTick, $ticksPerYear);
    }
}
