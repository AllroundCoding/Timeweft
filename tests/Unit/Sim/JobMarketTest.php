<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\JobMarket;
use App\Sim\Economy\JobRequest;
use App\Sim\Support\NameGenerator;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Species;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-97: the settlement labor market (design doc 16) — a settlement's unmet needs become scarcity-priced
 * job requests, and adults take them by the same participation calculus that drives cooperation. Needs in,
 * allocated labor out, by utility; the work an agent takes is the history a profession settles out of.
 */
class JobMarketTest extends TestCase
{
    private const TICK = 5 * 240 * 24; // a few years in, so every founder is a working-age adult

    public function test_an_unmet_need_becomes_a_priced_job(): void
    {
        $world = $this->village(); // a fresh settlement with an empty granary
        $population = count($world->village->livingAgents());

        $jobs = JobMarket::post($world, $world->village, $population);
        $farming = $this->jobOfType($jobs, 'farming');

        $this->assertNotNull($farming, 'an empty granary posts work to feed the settlement');
        $base = $world->goods->get('food')?->value ?? 1.0;
        $this->assertGreaterThan($base, $farming->price, 'a starving settlement pays above the base price');
        $this->assertSame('food', $farming->good, 'the job names the good it would supply (for distant suppliers, TWT-99)');
        $this->assertGreaterThan(0.0, $farming->shortfall, 'and how short the settlement is');
    }

    public function test_a_well_provisioned_settlement_posts_no_work(): void
    {
        $world = $this->village();
        $population = count($world->village->livingAgents());
        $world->village->stockpile->add('food', 10_000.0);
        $world->village->stockpile->add('water', 10_000.0);

        $this->assertSame([], JobMarket::post($world, $world->village, $population), 'plenty calls for no hands');
    }

    public function test_a_scarcer_need_pays_more(): void
    {
        $starving = $this->village();
        $starving->village->stockpile->add('food', 1.0 * count($starving->village->livingAgents())); // ~1 day/head
        $short = $this->village();
        $short->village->stockpile->add('food', 4.0 * count($short->village->livingAgents()));        // ~4 days/head

        $starvingPrice = $this->jobOfType(JobMarket::post($starving, $starving->village, count($starving->village->livingAgents())), 'farming')?->price ?? 0.0;
        $shortPrice = $this->jobOfType(JobMarket::post($short, $short->village, count($short->village->livingAgents())), 'farming')?->price ?? 0.0;

        $this->assertGreaterThan($shortPrice, $starvingPrice, 'the hungrier settlement bids more for the same work');
    }

    public function test_adults_take_posted_work_children_do_not(): void
    {
        $world = $this->village(); // empty granary → farming is posted
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        $child = Species::vulpini()->birth(9_999, self::TICK - 5 * $ticksPerYear, $world->region, $world->village->culture, new Rng('child'), NameGenerator::vaeris());
        $world->village->agents[] = $child;

        $this->runMarket($world, 5);

        $workers = array_filter($world->village->livingAgents(), static fn ($a): bool => $a->jobHistory !== []);
        $this->assertNotEmpty($workers, 'adults take up the posted work when their participation clears the threshold');
        $this->assertSame([], $child->jobHistory, 'a child is not in the labor market');
    }

    public function test_the_work_taken_follows_the_pressing_need(): void
    {
        // Hungry, but watered and well — hands go to the fields.
        $hungry = $this->village();
        $hungry->village->stockpile->add('water', 10_000.0);
        $this->runMarket($hungry, 20);
        $this->assertSame(['farming'], $this->workTaken($hungry), 'a hungry settlement puts its hands to farming');

        // Fed and watered, but sick — hands go to tending the ill.
        $sick = $this->village();
        $sick->village->stockpile->add('food', 10_000.0);
        $sick->village->stockpile->add('water', 10_000.0);
        foreach ($sick->village->livingAgents() as $agent) {
            $agent->needs['sickness']->value = 60.0;
        }
        $this->runMarket($sick, 20);
        $this->assertSame(['tending'], $this->workTaken($sick), 'a sick settlement puts its hands to tending');
    }

    public function test_it_is_deterministic(): void
    {
        $a = $this->village();
        $this->runMarket($a, 15);
        $b = $this->village();
        $this->runMarket($b, 15);

        $this->assertSame($this->histories($a), $this->histories($b), 'the labor allocation is a pure function of its inputs');
    }

    public function test_the_market_moves_no_goods_or_wages(): void
    {
        $world = $this->village(); // farming is posted, so the market is active
        $world->village->stockpile->add('money', 100.0);
        $world->village->stockpile->add('food', 3.0 * count($world->village->livingAgents()));
        $foodBefore = $world->village->stockpile->amount('food');
        $moneyBefore = $world->village->stockpile->amount('money');

        $this->runMarket($world, 5);

        $this->assertSame($foodBefore, $world->village->stockpile->amount('food'), 'posting work grows no food');
        $this->assertSame($moneyBefore, $world->village->stockpile->amount('money'), 'and pays no wage in v1 — only records who would do it');
    }

    private function village(): World
    {
        return World::seedTharadosVillage(new Rng('jobs'), 8);
    }

    private function runMarket(World $world, int $days): void
    {
        $tick = self::TICK;
        for ($day = 0; $day < $days; $day++) {
            $tick += TharadiCalendar::HOURS_PER_DAY;
            JobMarket::runDay($world, $tick);
        }
    }

    /**
     * @param  list<JobRequest>  $jobs
     */
    private function jobOfType(array $jobs, string $type): ?JobRequest
    {
        foreach ($jobs as $job) {
            if ($job->type === $type) {
                return $job;
            }
        }

        return null;
    }

    /** @return list<string> the distinct kinds of work the settlement's people have taken, sorted */
    private function workTaken(World $world): array
    {
        $types = [];
        foreach ($world->village->livingAgents() as $agent) {
            foreach (array_keys($agent->jobHistory) as $type) {
                $types[$type] = true;
            }
        }
        $keys = array_keys($types);
        sort($keys);

        return $keys;
    }

    /** @return array<int,array<string,int>> each living agent's job history, keyed by id */
    private function histories(World $world): array
    {
        $out = [];
        foreach ($world->village->livingAgents() as $agent) {
            $out[$agent->id] = $agent->jobHistory;
        }
        ksort($out);

        return $out;
    }
}
