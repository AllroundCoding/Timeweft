<?php

namespace App\Sim\Behavior;

use App\Sim\Persistence\Texture;

/**
 * What an agent is doing in a tick — the canonical example of {@see Texture} (doc 01): a pure function
 * of the agent's state and the date ({@see BehaviorEngine::derive}), recomputable on demand and never
 * persisted as canon.
 */
enum Activity: string implements Texture
{
    case Sleeping = 'sleeping';
    case Eating = 'eating';
    case Working = 'working';
    case Resting = 'resting';
    case Socializing = 'socializing';
    case Sheltering = 'sheltering';
    case Celebrating = 'celebrating';
    case Contributing = 'contributing';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Single-character code for compact activity grids. */
    public function code(): string
    {
        return match ($this) {
            self::Sleeping => 'S',
            self::Eating => 'E',
            self::Working => 'W',
            self::Resting => 'R',
            self::Socializing => 'O',
            self::Sheltering => 'H',
            self::Celebrating => 'C',
            self::Contributing => 'P',
        };
    }
}
