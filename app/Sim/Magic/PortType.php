<?php

namespace App\Sim\Magic;

/**
 * What flows along a wire between two magic nodes. A wire connects only compatible ports: raw {@see Energy}
 * drawn from a source must be given form before a sink can consume it as {@see Shaped} current.
 */
enum PortType: string
{
    /** Raw magical current, as a source emits it — undirected, unshaped. */
    case Energy = 'energy';

    /** Current that has been given form, target, or duration — what a sink turns into a world-effect. */
    case Shaped = 'shaped';
}
