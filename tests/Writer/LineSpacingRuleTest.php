<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Writer;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Element\Run;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Dskripchenko\PhpDocx\Writer\BodyXmlBuilder;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `w:lineRule` decides what `w:line` means: under `auto` it is a fraction of
 * single spacing (240 = single, 276 = 1.15), under `exact`/`atLeast` a height
 * in twips. The writer used to write `exact` whatever
 * ParagraphStyle::$lineSpacingRule said, so a document read with 1.15
 * spacing came back with lines fixed at 13.8pt, and larger text was clipped.
 */
final class LineSpacingRuleTest extends TestCase
{
    public static function rules(): array
    {
        return [
            'auto' => ['auto', 'auto'],
            'exact' => ['exact', 'exact'],
            'atLeast' => ['atLeast', 'atLeast'],
            'unset keeps exact' => [null, 'exact'],
            'unknown keeps exact' => ['bogus', 'exact'],
        ];
    }

    #[Test]
    #[DataProvider('rules')]
    public function writer_emits_the_rule_from_the_style(?string $rule, string $expected): void
    {
        $xml = (new BodyXmlBuilder)->render([
            new Paragraph([new Run('x')], new ParagraphStyle(lineSpacingTwips: 276, lineSpacingRule: $rule)),
        ]);

        self::assertStringContainsString('<w:spacing w:line="276" w:lineRule="'.$expected.'"/>', $xml);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function autoSpacings(): array
    {
        return [
            'explicit auto' => ['<w:spacing w:line="276" w:lineRule="auto"/>'],
            // The schema default for a missing lineRule is auto.
            'no lineRule' => ['<w:spacing w:line="276"/>'],
        ];
    }

    #[Test]
    #[DataProvider('autoSpacings')]
    public function auto_line_spacing_survives_read_then_write(string $spacing): void
    {
        $source = self::docxWithBody('<w:p><w:pPr>'.$spacing.'</w:pPr><w:r><w:t>x</w:t></w:r></w:p>');

        $document = (new DocxReader)->read($source);
        $written = self::documentXml((new Word2007Writer)->write($document));

        self::assertStringContainsString('<w:spacing w:line="276" w:lineRule="auto"/>', $written);
    }

    /**
     * A package the library writes, with word/document.xml swapped for the
     * given body, the way Word would have written it.
     */
    private static function docxWithBody(string $bodyInner): string
    {
        $bytes = DocumentBuilder::new()->paragraph('placeholder')->toBytes();
        $tmp = (string) tempnam(sys_get_temp_dir(), 'docx-rule-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$bodyInner.'</w:body></w:document>',
        );
        $zip->close();
        $out = (string) file_get_contents($tmp);
        unlink($tmp);

        return $out;
    }

    private static function documentXml(string $bytes): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'docx-rule-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);

        return $xml;
    }
}
