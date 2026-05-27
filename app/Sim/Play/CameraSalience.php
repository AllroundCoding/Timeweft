<?php

namespace App\Sim\Play;

use App\Sim\World\Village;
use App\Sim\World\World;

/**
 * The camera is the LOD salience source (design doc 23; TWT-285) — the key tie between the zoomable view
 * and the engine's level of detail. Where you look *is* attention: the settlements inside the camera's
 * {@see Viewport} are marked salient ({@see World::$salient}), so the LOD manager keeps them tracked at
 * full per-agent detail; zoom out and they fall from view, free to fold back into statistical cohorts
 * (TWT-248/49). Detail — sim and visual — follows the lens, and population is conserved either way.
 *
 * A pure projection from a viewport to a salient set; it sets the boundary-supplied {@see World::$salient}
 * and nothing else. A headless run has no camera and never calls this, so salience stays empty and the
 * canonical run is byte-identical; on a single-settlement world it is a no-op (the lone village is always
 * tracked).
 */
final class CameraSalience
{
    /**
     * Focus the camera: the settlements within the viewport become the salient set (replacing the prior
     * camera focus), the rest fall away. Returns the now-salient settlement names, in stable order.
     *
     * @return list<string>
     */
    public static function focus(World $world, Viewport $viewport): array
    {
        $salient = [];
        foreach ($world->villages as $village) {
            if ($viewport->contains($village->x, $village->y)) {
                $salient[$village->name] = true;
            }
        }
        ksort($salient); // stable, view-order-independent

        $world->salient = $salient;

        return array_keys($salient);
    }

    /** Whether a settlement currently sits within the camera's focus. */
    public static function sees(Viewport $viewport, Village $village): bool
    {
        return $viewport->contains($village->x, $village->y);
    }
}
