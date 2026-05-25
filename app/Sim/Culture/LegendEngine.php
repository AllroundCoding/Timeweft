<?php

namespace App\Sim\Culture;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;
use App\Sim\World\World;

/**
 * Myth & legend generation (design doc 11/14; TWT-143): turns the engine's real history into in-world
 * legends — the lore a worldbuilder would otherwise write by hand. Once a year a people looks back over
 * its chronicle; the turning points worth remembering — a founding, a war, a plague, a fall — crystallise
 * into {@see Legend}s, while the routine is forgotten. Each legend traces to the real event that seeded
 * it and drifts more mythic with age: the mundane turns miraculous, the cause becomes destiny.
 *
 * Additive and byte-identical: legends are a separate corpus ({@see World::$legends}), never written into
 * the factual chronicle, and every embellishment draws a forked `legend` sub-stream — so the canonical
 * run's chronicle and roster are untouched. A global author (it reads the whole history): it is
 * suppressed inside a decomposed region and run once on the merged world at the barrier, like the director.
 */
final class LegendEngine
{
    /** An event below this remembrance bar is never legendary — see {@see classify}. */
    private const DRIFT_YEARS = 60; // years over which a fresh memory drifts to fully mythic (embellishment 0 → 1)

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // a people reckons its memory once a year, at the turn of the year
        }

        self::crystallise($world, $tick);
        self::drift($world, $tick);
    }

    /** Crystallise legends from the legend-worthy events recorded since the last reckoning. */
    private static function crystallise(World $world, int $tick): void
    {
        foreach ($world->chronicle->all() as $event) {
            if ($event->tick <= $world->legendsThroughTick) {
                continue; // already weighed in an earlier reckoning
            }
            $kind = self::classify($event->type);
            if ($kind === null) {
                continue; // a routine event — forgotten, never legendary
            }
            $legend = self::compose($world, $event, $kind, $tick);
            if (self::alreadyTold($world, $legend)) {
                continue; // a people keeps one tale of each motif — the first occurrence is the canonical one
            }
            $world->legends[] = $legend;
        }
        $world->legendsThroughTick = $tick;
    }

    /** Whether this people already keeps a tale of this motif — recurring same-motif events fold into the first. */
    private static function alreadyTold(World $world, Legend $candidate): bool
    {
        foreach ($world->legends as $legend) {
            if ($legend->motif === $candidate->motif && $legend->rememberedBy === $candidate->rememberedBy) {
                return true;
            }
        }

        return false;
    }

    /** Each year a legend already told drifts a little more mythic; its telling is re-spun to match. */
    private static function drift(World $world, int $tick): void
    {
        $full = self::DRIFT_YEARS * TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        foreach ($world->legends as $legend) {
            $embellishment = min(1.0, ($tick - $legend->bornTick) / $full);
            if ($embellishment > $legend->embellishment) {
                $legend->embellishment = $embellishment;
                $legend->telling = self::tell($legend, $world->rng->stream('legend-tell', $legend->sourceEventId));
            }
        }
    }

    /** Map a chronicle event type to the legend it becomes — or null if it is too routine to remember. */
    public static function classify(string $type): ?LegendKind
    {
        return match ($type) {
            'institution-founded' => LegendKind::FoundingMyth,
            'collapse', 'institution-collapsed', 'shock-plague', 'contagion' => LegendKind::Catastrophe,
            'war', 'shock-raid' => LegendKind::HeroTale,
            'relations-alliance' => LegendKind::Triumph,
            default => null,
        };
    }

    /** Crystallise one event into a fresh, still-factual legend, naming a figure at its heart if there was one. */
    private static function compose(World $world, ChronicleEvent $event, LegendKind $kind, int $tick): Legend
    {
        $rng = $world->rng->stream('legend', $event->id);

        $heroId = $event->subjects === [] ? null : $event->subjects[$rng->int(0, count($event->subjects) - 1)];
        $heroName = null;
        $rememberedBy = $world->villages !== [] ? $world->villages[0]->name : 'the world';
        if ($heroId !== null) {
            foreach ($world->villages as $village) {
                foreach ($village->agents as $agent) {
                    if ($agent->id === $heroId) {
                        $heroName = $agent->name;
                        $rememberedBy = $village->name;
                        break 2;
                    }
                }
            }
        }

        $legend = new Legend(
            sourceEventId: $event->id,
            motif: $event->type,
            kind: $kind,
            rememberedBy: $rememberedBy,
            heroId: $heroId,
            heroName: $heroName,
            eventYear: TharadiCalendar::fromTick($event->tick)->year,
            bornTick: $tick,
            embellishment: 0.0,
            title: '',
            telling: '',
        );
        $legend->title = self::title($legend);
        $legend->telling = self::tell($legend, $rng);

        return $legend;
    }

    /** The legend's name — a worldbuilder's index line. */
    private static function title(Legend $legend): string
    {
        $where = $legend->rememberedBy;

        return match ($legend->kind) {
            LegendKind::FoundingMyth => "The Founding of {$where}",
            LegendKind::Catastrophe => match ($legend->motif) {
                'collapse' => "The Fall of {$where}",
                'institution-collapsed' => "The Broken Order of {$where}",
                default => "The Great Sickness of {$where}",
            },
            LegendKind::HeroTale => $legend->heroName !== null ? "The Tale of {$legend->heroName}" : "The War upon {$where}",
            LegendKind::Triumph => "The Pact of {$where}",
        };
    }

    /**
     * Spin the telling: a factual core, then clauses layered on as the memory drifts mythic — a remembered
     * figure, then the cause-become-destiny. Deterministic per legend (the forked stream gives it a stable
     * voice), so re-telling an older, more-embellished legend reads as the same tale, grown taller.
     */
    private static function tell(Legend $legend, Rng $rng): string
    {
        $where = $legend->rememberedBy;
        $year = $legend->eventYear;
        $hero = $legend->heroName;

        $core = match ($legend->kind) {
            LegendKind::FoundingMyth => "In Year {$year}, {$where} raised a new power to bind its people.",
            LegendKind::Catastrophe => match ($legend->motif) {
                'collapse' => "In Year {$year}, {$where} fell silent; its people were gone.",
                'institution-collapsed' => "In Year {$year}, the order that ruled {$where} crumbled.",
                default => "In Year {$year}, a sickness swept through {$where}, and many were lost.",
            },
            LegendKind::HeroTale => $legend->motif === 'war'
                ? "In Year {$year}, war came to {$where}."
                : "In Year {$year}, raiders fell upon {$where}.",
            LegendKind::Triumph => "In Year {$year}, {$where} swore an alliance that held.",
        };

        $parts = [$core];

        if ($legend->embellishment >= 0.34) {
            $parts[] = $hero !== null
                ? self::pick($rng, ["They remember {$hero} above all.", "{$hero} stood at the heart of it.", "It is {$hero}'s name the tale keeps."])
                : self::pick($rng, ['The old folk still mark the year.', 'None who saw it spoke of it lightly.']);
        }

        if ($legend->embellishment >= 0.67) {
            $parts[] = match ($legend->kind) {
                LegendKind::FoundingMyth => self::pick($rng, ['The gods, they say, had willed it so.', 'It was fated before the first stone was laid.']),
                LegendKind::Catastrophe => self::pick($rng, ['The place is shunned to this day.', 'They say the sky itself had turned against them.']),
                LegendKind::HeroTale => self::pick($rng, ['In the telling now, no blade could touch them.', 'Children are named for that year still.']),
                LegendKind::Triumph => self::pick($rng, ['The oath is sworn over to this day.', 'It is held a blessing on the line.']),
            };
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<string>  $options
     */
    private static function pick(Rng $rng, array $options): string
    {
        return $options[$rng->int(0, count($options) - 1)];
    }
}
