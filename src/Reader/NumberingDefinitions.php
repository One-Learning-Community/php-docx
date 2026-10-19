<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\ListFormat;

/**
 * The definitions from `numbering.xml`, resolved from the concrete numId
 * through abstractNumId down to the per-level structure.
 *
 * Map numId → level → { format, startAt }
 */
final readonly class NumberingDefinitions
{
    /**
     * @param  array<int, array<int, array{format?: ListFormat, startAt?: int, levelText?: string|null, indentLeft?: int|null}>>  $byNumId
     * @param  array<int, int>  $abstractOf  numId → abstractNumId
     * @param  array<int, true>  $restarts  numIds with a level override — they restart the numbering
     */
    public function __construct(
        public array $byNumId = [],
        public array $abstractOf = [],
        public array $restarts = [],
    ) {}

    /** Where a level's text starts, in twips from the margin; null when the level does not say. */
    public function indentLeftFor(int $numId, int $level = 0): ?int
    {
        return $this->byNumId[$numId][$level]['indentLeft'] ?? null;
    }

    /** Word's label pattern of a level (`%1.%2.`); null when not given. */
    public function levelTextFor(int $numId, int $level = 0): ?string
    {
        $text = $this->byNumId[$numId][$level]['levelText'] ?? null;

        return $text !== null && $text !== '' ? $text : null;
    }

    /**
     * What a list's numbers continue from: lists of one abstract numbering
     * count on together in Word, unless an instance restarts with a level
     * override.
     */
    public function counterKey(int $numId): string
    {
        return isset($this->restarts[$numId]) || ! isset($this->abstractOf[$numId])
            ? 'n'.$numId
            : 'a'.$this->abstractOf[$numId];
    }

    public function formatFor(int $numId, int $level = 0): ListFormat
    {
        return $this->byNumId[$numId][$level]['format']
            ?? $this->byNumId[$numId][0]['format']
            ?? ListFormat::Bullet;
    }

    public function startAtFor(int $numId, int $level = 0): int
    {
        return $this->byNumId[$numId][$level]['startAt']
            ?? $this->byNumId[$numId][0]['startAt']
            ?? 1;
    }

    public function isOrdered(int $numId, int $level = 0): bool
    {
        $fmt = $this->formatFor($numId, $level);

        return $fmt !== ListFormat::Bullet;
    }

    public function hasNumId(int $numId): bool
    {
        return isset($this->byNumId[$numId]);
    }
}
