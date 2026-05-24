<?php

namespace App\Narrative;

/**
 * The shared chronicler brief and prompt assembly used by every LLM-backed narrator (the API and the
 * Claude Code transports) — so a saga reads the same whichever path carries it to the model.
 */
trait ChroniclerPrompt
{
    /** Identical across every saga, so it sits in the cacheable system prefix. */
    private const VOICE = <<<'PROMPT'
        You are the chronicler of Timeweft, a world of emergent history. You are given the canonical
        chronicle of a single settlement: events that actually happened, in order, by year. Retell it as
        a short, vivid saga in flowing prose.

        Rules:
        - Describe only what the chronicle records. You may add atmosphere — the desert heat, the turn of
          the seasons, the weight of a death — but never invent people, events, or outcomes beyond the
          given facts.
        - Write two to five short paragraphs, roughly chronological, separated by a blank line.
        - Name the people and the settlement as given. Let cause and consequence show: a plague, then the
          deaths it brought; a cooperation deficit, then the temple founded to mend it.
        - Tone: the measured, faintly mythic voice of a historian recording a people's rise and fall. No
          headings, no lists, no preamble — just the saga.
        PROMPT;

    private function material(Saga $saga): string
    {
        $events = array_map(
            static fn (array $event): string => "- Year {$event['year']}: {$event['text']}",
            $saga->events,
        );

        return implode("\n", [
            "Settlement: {$saga->world}, in the {$saga->region}. The chronicle spans Year {$saga->startYear} to Year {$saga->endYear}.",
            "Population: {$saga->population['founders']} founders, {$saga->population['born']} born, {$saga->population['died']} died, {$saga->population['living']} living at the close.",
            '',
            'Canonical events (retell every one; invent nothing beyond them):',
            ...$events,
        ]);
    }
}
