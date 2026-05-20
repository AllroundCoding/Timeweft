<?php

namespace App\Sim\Traits;

/** How a trait's value is represented and inherited. */
enum TraitType
{
    /** A 0..100 quantity: drawn in a range at birth, averaged + mutated when inherited. */
    case Numeric;
    /** A choice from region-supplied options: picked at birth, taken from one parent when inherited. */
    case Categorical;
}
