<?php

namespace App\Sim\Play;

/**
 * The slice of the world the camera is looking at, in world coordinates (design doc 23; TWT-285). The
 * boundary (the zoomable view) describes its camera as a Viewport — pan sets the centre, zoom sets the
 * extent — and hands it to {@see CameraSalience} so attention can follow the lens. A plain value object;
 * the pixels and zoom levels stay in the view, only world-space bounds cross into the core.
 */
final readonly class Viewport
{
    public function __construct(
        public float $minX,
        public float $minY,
        public float $maxX,
        public float $maxY,
    ) {}

    /** A square view of the given half-extent around a centre — pan = centre, zoom = a smaller radius. */
    public static function around(float $centerX, float $centerY, float $radius): self
    {
        return new self($centerX - $radius, $centerY - $radius, $centerX + $radius, $centerY + $radius);
    }

    public function contains(float $x, float $y): bool
    {
        return $x >= $this->minX && $x <= $this->maxX && $y >= $this->minY && $y <= $this->maxY;
    }
}
