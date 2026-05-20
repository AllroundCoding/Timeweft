<?php

namespace App\Sim\World;

use App\Sim\Time\TharadiCalendar;

final class Agent
{
    /**
     * @param array<string,float|string> $traits
     * @param array<string,Need> $needs
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
    ) {}

    public function trait(string $key): float|string|null
    {
        return $this->traits[$key] ?? null;
    }

    public function advanceNeeds(int $ticks = 1, float $multiplier = 1.0): void
    {
        foreach ($this->needs as $need) {
            $need->advance($ticks, $multiplier);
        }
    }

    public function ageInYears(int $nowTick): int
    {
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        return intdiv($nowTick - $this->birthTick, $ticksPerYear);
    }
}
