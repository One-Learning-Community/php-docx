<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Html;

use Dskripchenko\PhpDocx\Html\Converter;
use Dskripchenko\PhpDocx\Html\Serializer;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Word's multilevel numbering through HTML and back: a list broken off by
 * a paragraph numbers on (`3.`), the level below shows its parent's number
 * (`3.1.`), a level with a literal prefix keeps it (`4.1.`).
 */
final class WordNumberingRoundTripTest extends TestCase
{
    private const HTML = '<style>.wl5-1::marker { content: counters(list-item, ".") ". " }'
        .'.wl7-0::marker { content: "4." counter(list-item) ". " }</style>'
        .'<ol><li>One<ol><li class="wl5-1">One-one</li></ol></li></ol>'
        .'<p>Break</p>'
        .'<ol start="3"><li style="list-style-type:none"><ol><li class="wl5-1">Three-one</li><li class="wl5-1">Three-two</li></ol></li></ol>'
        .'<ol><li class="wl7-0">Four-one</li></ol>';

    #[Test]
    public function html_labels_become_word_numbering(): void
    {
        $doc = (new Converter)->fromHtml(self::HTML);
        $docx = (new Word2007Writer)->write($doc);
        $numbering = self::part($docx, 'word/numbering.xml');
        $body = self::part($docx, 'word/document.xml');

        self::assertStringContainsString('<w:lvlText w:val="%1.%2."/>', $numbering);
        self::assertStringContainsString('<w:lvlText w:val="4.%1."/>', $numbering);
        // The label-less parent writes no paragraph; its number is the start.
        self::assertStringContainsString('<w:start w:val="3"/>', $numbering);
        self::assertSame(5, substr_count($body, '<w:numPr>'));
    }

    #[Test]
    public function word_numbering_survives_the_round_trip(): void
    {
        $docx = (new Word2007Writer)->write((new Converter)->fromHtml(self::HTML));
        $imported = (new Serializer)->serialize((new DocxReader)->read($docx));

        self::assertStringContainsString('counters(list-item, ".") ". "', $imported->css);
        self::assertStringContainsString('"4." counter(list-item) ". "', $imported->css);
        self::assertStringContainsString('<ol start="3"><li style="list-style-type:none"><ol>', $imported->bodyHtml);
    }

    #[Test]
    public function marker_rules_pass_an_inliner(): void
    {
        self::assertSame(
            '<style>.wl5-1::marker { content: counters(list-item, ".") ". " }'."\n"
            .'.wl7-0::marker { content: "4." counter(list-item) ". " }'."\n".'</style>',
            Converter::markerStyleOf(self::HTML),
        );
        self::assertSame('', Converter::markerStyleOf('<p>x</p>'));
    }

    private static function part(string $docx, string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $docx);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName($name);
        $zip->close();
        @unlink($path);

        return $xml;
    }
}
