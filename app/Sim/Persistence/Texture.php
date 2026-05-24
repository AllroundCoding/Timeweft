<?php

namespace App\Sim\Persistence;

/**
 * Marks a dense, **derived** type — texture (design doc 01): "what is X doing at noon on day 3", a pure
 * function of (skeleton/checkpoint, seed, query). Recomputable on demand and never the source of truth,
 * so it need not be persisted — storage grows with *attention*, not with time × population.
 *
 * The counterpart of {@see Skeleton}. A type implements `Texture` to declare "I can always be
 * regenerated; do not store me as canon." Derive-on-demand (TWT-38) reconstructs texture from the
 * nearest checkpoint. The full contract lives in docs/conventions.md.
 */
interface Texture {}
