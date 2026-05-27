<?php

namespace App\Sim\Magic;

/**
 * The three families of magic node (design doc 20). A spell flows left to right: a {@see Source} draws raw
 * current, {@see Transform}s shape and amplify it, and a {@see Sink} spends it as a world-effect.
 */
enum NodeKind: string
{
    case Source = 'source';
    case Transform = 'transform';
    case Sink = 'sink';
}
