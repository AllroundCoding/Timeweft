<?php

namespace Tests\Unit\Sim;

use App\Sim\Projects\Project;
use PHPUnit\Framework\TestCase;

class ProjectTest extends TestCase
{
    public function test_a_project_carries_a_type_and_an_initiator(): void
    {
        $storm = new Project('Sandstorm preparation', 100, 300.0, type: 'seasonal-preparation', initiator: 'the coming Sandstorm');

        $this->assertSame('seasonal-preparation', $storm->type);
        $this->assertSame('the coming Sandstorm', $storm->initiator);
    }

    public function test_readiness_is_effort_over_required(): void
    {
        $project = new Project('Granary', 100, 100.0);
        $project->contribute(40.0);

        $this->assertEqualsWithDelta(0.4, $project->readiness(), 1e-9);
    }

    public function test_different_project_types_track_effort_independently(): void
    {
        $prep = new Project('Sandstorm preparation', 100, 100.0, type: 'seasonal-preparation', initiator: 'the coming Sandstorm');
        $monument = new Project('Sun shrine', 100, 50.0, type: 'monument', initiator: 'a devout elder');

        $prep->contribute(50.0);
        $monument->contribute(50.0);

        $this->assertEqualsWithDelta(0.5, $prep->readiness(), 1e-9);
        $this->assertEqualsWithDelta(1.0, $monument->readiness(), 1e-9);
        $this->assertNotSame($prep->type, $monument->type);
    }
}
