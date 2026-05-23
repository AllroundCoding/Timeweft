<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Economy\JobMarket;
use App\Sim\Economy\ProfessionEngine;
use App\Sim\Support\NameGenerator;
use App\Sim\Support\Rng;
use App\Sim\World\Agent;
use App\Sim\World\RegionProfile;
use App\Sim\World\Species;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-98: professions & division of labor (design doc 16) — an agent settles into a trade out of the
 * work it repeatedly takes in the labor market (TWT-97), nudged by disposition. The role is sticky
 * (it holds against a stray off-day, yielding only to a sustained shift), biases which work it reaches
 * for, and makes it more productive at its own trade. Specialization with a bottom-up origin.
 */
class ProfessionTest extends TestCase
{
    private const TICK = 5 * 240 * 24;

    public function test_an_agent_settles_into_the_work_it_repeatedly_does(): void
    {
        $agent = $this->agent();
        $agent->jobHistory = ['farming' => 40, 'building' => 3];

        ProfessionEngine::settle($agent);

        $this->assertSame('farming', $agent->profession, 'the work most done becomes the trade');
    }

    public function test_a_green_hand_has_no_trade_yet(): void
    {
        $agent = $this->agent();
        $agent->jobHistory = ['farming' => 5]; // too little work to count as a trade

        ProfessionEngine::settle($agent);

        $this->assertNull($agent->profession, 'a trade is earned, not assigned');
    }

    public function test_a_settled_trade_holds_against_a_stray_spell_of_other_work(): void
    {
        $agent = $this->agent();
        $agent->jobHistory = ['farming' => 40];
        ProfessionEngine::settle($agent);

        $agent->jobHistory['building'] = 45; // building now leads, but not by the margin a switch needs
        ProfessionEngine::settle($agent);

        $this->assertSame('farming', $agent->profession, 'a marginal lead does not unseat a settled trade');
    }

    public function test_a_sustained_shift_changes_the_trade(): void
    {
        $agent = $this->agent();
        $agent->jobHistory = ['farming' => 40];
        ProfessionEngine::settle($agent);

        $agent->jobHistory['building'] = 90; // a clear, sustained move to other work
        ProfessionEngine::settle($agent);

        $this->assertSame('building', $agent->profession, 'work that clearly overtakes the old trade becomes the new one');
    }

    public function test_a_settled_trade_biases_which_work_is_taken(): void
    {
        $world = World::seedTharadosVillage(new Rng('prof'), 1); // empty granary → farming and water-bearing both posted
        $agent = $world->village->livingAgents()[0];
        // Traits that, untrained, favour water-bearing over farming.
        $agent->traits['conscientiousness'] = 60.0;
        $agent->traits['constitution'] = 60.0;  // farming affinity 0.60
        $agent->traits['heatTolerance'] = 85.0;
        $agent->traits['agility'] = 85.0;        // water-bearing affinity 0.85
        $agent->traits['sociability'] = 90.0;    // clears the participation threshold comfortably

        JobMarket::runDay($world, self::TICK);
        $this->assertArrayHasKey('water-bearing', $agent->jobHistory, 'untrained, it reaches for the work it is naturally best at');
        $this->assertArrayNotHasKey('farming', $agent->jobHistory);

        $agent->jobHistory = [];
        $agent->profession = 'farming';
        JobMarket::runDay($world, self::TICK);
        $this->assertArrayHasKey('farming', $agent->jobHistory, 'settled as a farmer, it reaches for the fields — the role locks in');
        $this->assertArrayNotHasKey('water-bearing', $agent->jobHistory);
    }

    public function test_a_trade_makes_an_agent_more_productive_at_it(): void
    {
        $this->assertGreaterThan(
            ProfessionEngine::productivity('farming', 'building'),
            ProfessionEngine::productivity('farming', 'farming'),
            'a farmer produces more at farming than at unfamiliar work',
        );
        $this->assertSame(1.0, ProfessionEngine::productivity(null, 'farming'), 'an untrained hand works at the baseline');
    }

    public function test_roles_emerge_over_a_run(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(3 * 240 * 24); // three years of work

        $professed = array_filter($world->village->livingAgents(), static fn (Agent $a): bool => $a->profession !== null);
        $this->assertNotEmpty($professed, 'people settle into trades over a life of work');
    }

    public function test_it_is_deterministic(): void
    {
        $a = $this->agent();
        $a->jobHistory = ['farming' => 30, 'building' => 12];
        $b = $this->agent();
        $b->jobHistory = ['farming' => 30, 'building' => 12];

        ProfessionEngine::settle($a);
        ProfessionEngine::settle($b);

        $this->assertSame($a->profession, $b->profession, 'a trade is a pure function of history and disposition');
    }

    private function agent(): Agent
    {
        return Species::vulpini()->birth(1, -30 * 240 * 24, RegionProfile::tharados(), Culture::tharados(), new Rng('prof'), NameGenerator::vaeris());
    }
}
