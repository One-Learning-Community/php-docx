<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

/**
 * The footnote texts from `word/footnotes.xml`, by their identifiers.
 *
 * The text and the size it is set in at the foot of the page — notes are
 * usually smaller than the body (7–10 pt), and printed at the body's size a
 * note takes lines the page does not have. The rest of the formatting is not
 * carried over.
 */
final class FootnoteReader
{
    /** @var array<int, string> */
    private array $byId = [];

    /** @var array<int, float> */
    private array $sizeById = [];

    public function __construct(?\DOMDocument $footnotesXml)
    {
        if ($footnotesXml === null) {
            return;
        }

        foreach ($footnotesXml->getElementsByTagNameNS(OoxmlNs::W, 'footnote') as $footnote) {
            // The separators (`separator`, `continuationSeparator`) are internal
            // presentation parts and carry no text.
            $type = $footnote->getAttributeNS(OoxmlNs::W, 'type');
            if ($type !== '') {
                continue;
            }

            $id = $footnote->getAttributeNS(OoxmlNs::W, 'id');
            if ($id === '' || ! ctype_digit(ltrim($id, '-'))) {
                continue;
            }

            $parts = [];
            $first = true;
            unset($size);
            foreach ($footnote->getElementsByTagNameNS(OoxmlNs::W, 'r') as $run) {
                // A footnote mark is its number. Word places it either with the
                // internal `w:footnoteRef` or simply as a superscript in the
                // first run. The print engine draws the numbering — otherwise
                // the result reads «1. 1Текст».
                $isMarker = $run->getElementsByTagNameNS(OoxmlNs::W, 'footnoteRef')->length > 0
                    || ($first && $this->isSuperscript($run));
                $first = false;
                if ($isMarker) {
                    continue;
                }
                foreach ($run->getElementsByTagNameNS(OoxmlNs::W, 't') as $t) {
                    $parts[] = $t->textContent;
                }
                // The size of the first run of text is the note's size.
                if (! isset($size) && $run->getElementsByTagNameNS(OoxmlNs::W, 't')->length > 0) {
                    $sz = $run->getElementsByTagNameNS(OoxmlNs::W, 'sz')->item(0);
                    $val = $sz instanceof \DOMElement ? $sz->getAttributeNS(OoxmlNs::W, 'val') : '';
                    $size = ctype_digit($val) ? ((int) $val) / 2 : null;
                }
            }
            $text = trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
            if ($text !== '') {
                $this->byId[(int) $id] = $text;
                if (isset($size)) {
                    $this->sizeById[(int) $id] = $size;
                }
            }
        }
    }

    private function isSuperscript(\DOMElement $run): bool
    {
        foreach ($run->getElementsByTagNameNS(OoxmlNs::W, 'vertAlign') as $vertAlign) {
            if ($vertAlign->getAttributeNS(OoxmlNs::W, 'val') === 'superscript') {
                return true;
            }
        }

        return false;
    }

    public function text(int $id): ?string
    {
        return $this->byId[$id] ?? null;
    }

    /** The note's size in points, when its text says it. */
    public function size(int $id): ?float
    {
        return $this->sizeById[$id] ?? null;
    }
}
