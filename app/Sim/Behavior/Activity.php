<?php

namespace App\Sim\Behavior;

enum Activity: string
{
    case Sleeping = 'sleeping';
    case Eating = 'eating';
    case Working = 'working';
    case Resting = 'resting';
    case Socializing = 'socializing';
    case Sheltering = 'sheltering';
    case Celebrating = 'celebrating';

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
        };
    }
}
