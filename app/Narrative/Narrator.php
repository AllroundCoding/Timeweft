<?php

namespace App\Narrative;

use App\Sim\Direction\Director;

/**
 * The flavor layer's seam (TWT-53), mirroring the pluggable {@see Director}: it turns
 * the deterministic chronicle into prose, and is the one place an LLM is allowed to live. Whatever
 * narrates only *describes* the canonical events — it never alters the skeleton (design doc 01; the
 * deterministic core stays LLM-free). Implementations: the reproducible {@see TemplateNarrator} (the
 * default) and the opt-in {@see LlmNarrator}.
 */
interface Narrator
{
    /** Retell a run's chronicle as flowing prose. A pure description of the events; the world is unchanged. */
    public function retell(Saga $saga): string;
}
