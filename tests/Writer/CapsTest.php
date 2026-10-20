<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Style\RunStyle;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RunStyle::$allCaps / $smallCaps were parsed by the reader and serialized to
 * HTML, but the writer never emitted `<w:caps/>` / `<w:smallCaps/>`, so the
 * formatting was lost on the way back into a DOCX.
 */
final class CapsTest extends TestCase
{
    #[Test]
    public function writer_emits_caps_and_small_caps(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([
                new Run('a', new RunStyle(allCaps: true)),
                new Run('b', new RunStyle(smallCaps: true)),
            ]),
        ]);

        self::assertStringContainsString('<w:rPr><w:caps/></w:rPr><w:t xml:space="preserve">a</w:t>', $xml);
        self::assertStringContainsString('<w:rPr><w:smallCaps/></w:rPr><w:t xml:space="preserve">b</w:t>', $xml);
    }

    #[Test]
    public function caps_follow_italic_and_precede_strike(): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('x', new RunStyle(italic: true, strikethrough: true, allCaps: true, smallCaps: true))]),
        ]);

        self::assertStringContainsString('<w:i/><w:iCs/><w:caps/><w:smallCaps/>', $xml);
        self::assertLessThan(strpos($xml, '<w:strike/>'), strpos($xml, '<w:smallCaps/>'));
    }

    #[Test]
    public function caps_survive_write_then_read(): void
    {
        $bytes = (new Word2007Writer)->write(new Document(new Section([
            new Paragraph([
                new Run('a', new RunStyle(allCaps: true)),
                new Run('b', new RunStyle(smallCaps: true)),
            ]),
        ])));

        $paragraph = (new DocxReader)->read($bytes)->section->body[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        [$a, $b] = $paragraph->children;
        self::assertInstanceOf(Run::class, $a);
        self::assertInstanceOf(Run::class, $b);
        self::assertTrue($a->style->allCaps);
        self::assertFalse($a->style->smallCaps);
        self::assertTrue($b->style->smallCaps);
        self::assertFalse($b->style->allCaps);
    }
}
