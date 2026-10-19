<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Tests\Reader;

use Dskripchenko\PhpDocx\Build\DocumentBuilder;
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Reader\DocxReader;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which headers and footers a document shows, as Word decides it: the first
 * section's parts govern the document's start, first-page parts need
 * `<w:titlePg/>`, even-page ones `<w:evenAndOddHeaders/>`.
 */
final class SectionHeadersTest extends TestCase
{
    private static function docx(): string
    {
        return (new Word2007Writer)->write(DocumentBuilder::new()
            ->footer(fn ($f) => $f->paragraph('Default footer'))
            ->firstFooter(fn ($f) => $f->paragraph('First footer'))
            ->paragraph('Body')
            ->build());
    }

    /** @param  \Closure(string): string  $edit */
    private static function editDocumentXml(string $docx, \Closure $edit): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $docx);
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/document.xml', $edit((string) $zip->getFromName('word/document.xml')));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param  list<\Dskripchenko\PhpDocx\Element\BlockElement>  $blocks */
    private static function text(array $blocks): string
    {
        $p = $blocks[0] ?? null;

        return $p instanceof Paragraph ? implode('', array_map(static fn ($r) => $r->text ?? '', $p->children)) : '';
    }

    #[Test]
    public function first_page_parts_need_title_pg(): void
    {
        $with = (new DocxReader)->read(self::docx());
        self::assertSame('First footer', self::text($with->section->firstFooter));

        $without = (new DocxReader)->read(self::editDocumentXml(self::docx(), static fn (string $xml): string => str_replace('<w:titlePg/>', '', $xml)));
        self::assertSame([], $without->section->firstFooter);
        self::assertSame('Default footer', self::text($without->section->footer));
    }

    #[Test]
    public function the_first_sections_parts_win_over_later_sections(): void
    {
        // A section break before the body's own section: the first section
        // shows the first-page footer part as its default footer, without
        // titlePg.
        $bytes = self::editDocumentXml(self::docx(), static function (string $xml): string {
            preg_match('/<w:footerReference w:type="first" r:id="(\w+)"\/>/', $xml, $m);

            return (string) preg_replace(
                '/<w:p>/',
                '<w:p><w:pPr><w:sectPr><w:footerReference w:type="default" r:id="'.$m[1].'"/></w:sectPr></w:pPr>',
                $xml,
                1,
            );
        });

        $doc = (new DocxReader)->read($bytes);
        self::assertSame('First footer', self::text($doc->section->footer));
        self::assertSame([], $doc->section->firstFooter);
    }

    #[Test]
    public function even_parts_need_even_and_odd_headers(): void
    {
        $bytes = (new Word2007Writer)->write(DocumentBuilder::new()
            ->footer(fn ($f) => $f->paragraph('Odd'))
            ->evenFooter(fn ($f) => $f->paragraph('Even'))
            ->paragraph('Body')
            ->build());

        self::assertSame('Even', self::text((new DocxReader)->read($bytes)->section->evenFooter));
    }
}
