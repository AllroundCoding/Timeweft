<?php

namespace App\Narrative;

/**
 * The reproducible default narrator — no LLM, no network, no key. It composes the chronicle's own
 * recorded lines into a readable retelling, grouped by year. Deterministic: the same saga always yields
 * the same prose, so it anchors the tests and serves anyone who has not brought an LLM.
 */
final class TemplateNarrator implements Narrator
{
    public function retell(Saga $saga): string
    {
        $pop = $saga->population;
        $opening = sprintf(
            '%s, in the %s, kept its chronicle from Year %d to Year %d. Of %d founders, %d were born and %d died; %d souls remained when the record closed.',
            $saga->world, $saga->region, $saga->startYear, $saga->endYear,
            $pop['founders'], $pop['born'], $pop['died'], $pop['living'],
        );

        // Group the recorded events by year so the retelling reads as a year-by-year account.
        $byYear = [];
        foreach ($saga->events as $event) {
            $byYear[$event['year']][] = $this->asSentence($this->stripDate($event['text']));
        }
        ksort($byYear);

        $paragraphs = [$opening];
        foreach ($byYear as $year => $sentences) {
            $paragraphs[] = sprintf('Year %d — %s', $year, implode(' ', $sentences));
        }

        return implode("\n\n", $paragraphs);
    }

    /** The chronicle prefixes each line with its in-world date; drop it so the year heading carries the time. */
    private function stripDate(string $text): string
    {
        // "12 Varith, Year 4 — Thashim is born..." → "Thashim is born..."
        $marker = mb_strpos($text, '—');

        return $marker === false ? $text : trim(mb_substr($text, $marker + 1));
    }

    private function asSentence(string $line): string
    {
        $line = trim($line);

        return $line === '' ? '' : rtrim($line, '.').'.';
    }
}
