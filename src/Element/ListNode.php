<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * A list — `<w:p>` paragraphs carrying a `<w:numPr>` reference to an abstract
 * numbering.
 *
 * Supported:
 *  - bullet (●/○/■ for 3 levels)
 *  - ordered, with flexible formats via ListFormat (decimal/lowerLetter/
 *    upperLetter/lowerRoman/upperRoman) and a custom $startAt
 *  - nesting via ListItem.nestedList
 */
final readonly class ListNode implements BlockElement
{
    /**
     * @param  list<ListItem>  $items
     * @param  bool  $ordered  true → ordered, false → bullet
     * @param  int  $levelStart  The nesting level (0..2)
     * @param  ListFormat|null  $format  An override format. Null means the
     *                                   Bullet/Decimal default implied by
     *                                   $ordered.
     * @param  int  $startAt  The number to start from (default 1). Ignored for
     *                       bullet lists.
     * @param  string|null  $levelText  Word's label pattern for this level
     *                                  (`%1.%2.`, `4.%1.`): `%N` is the
     *                                  number of level N. Null — the plain
     *                                  `%1.` of the format.
     * @param  string|null  $markerClass  The class that names this list
     *                                    level in HTML: the label rule hangs
     *                                    on it.
     */
    public function __construct(
        public array $items,
        public bool $ordered = false,
        public int $levelStart = 0,
        public ?ListFormat $format = null,
        public int $startAt = 1,
        public ?string $levelText = null,
        public ?string $markerClass = null,
    ) {}

    public function effectiveFormat(): ListFormat
    {
        return $this->format ?? ($this->ordered ? ListFormat::Decimal : ListFormat::Bullet);
    }
}
