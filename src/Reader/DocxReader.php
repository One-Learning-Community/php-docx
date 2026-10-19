<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Document;
use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Exception\DocxException;
use Dskripchenko\PhpDocx\Section;

/**
 * Phase 8 — the high-level facade.
 *
 * DOCX bytes → Document (the AST). It coordinates every reader of phases 1-8.
 *
 * Header/footer types (default/first/even) are detected from
 * `<w:headerReference w:type="X" r:id="Y">` in sectPr — the pkg->headers
 * mapping (path→DOM) carries no type on its own.
 */
final class DocxReader
{
    /**
     * @throws DocxException
     */
    public function read(string $bytes): Document
    {
        $pkg = (new DocxPackageReader)->read($bytes);

        return $this->readPackage($pkg);
    }

    public function readPackage(DocxPackage $pkg): Document
    {
        $theme = new ThemeResolver($pkg->themeXml);
        $styles = new StylesResolver($pkg->stylesXml, $theme);
        $numbering = (new NumberingReader)->read($pkg->numberingXml);

        // Body
        $bodyEl = $pkg->documentXml->getElementsByTagNameNS(OoxmlNs::W, 'body')->item(0);
        if (! $bodyEl instanceof \DOMElement) {
            throw new DocxException('В word/document.xml отсутствует <w:body>.');
        }
        $bodyImgReader = new ImageReader($pkg, $pkg->documentPartPath);
        $footnotes = new FootnoteReader($pkg->footnotesXml);
        $bodyReader = new BodyReader(
            $styles,
            $numbering,
            $bodyImgReader,
            $pkg,
            $pkg->documentPartPath,
            $footnotes,
        );
        $body = $bodyReader->read($bodyEl);
        $pageSetup = (new SectionReader)->readPageSetup($bodyEl);

        // Map path → type through the references of the FIRST section: the
        // document is read as one section, and its first pages are what the
        // first section's parts govern. Taking the references of every
        // section let a later section's parts win, and a first-page footer
        // showed up where Word shows none.
        $sectPr = $bodyEl->getElementsByTagNameNS(OoxmlNs::W, 'sectPr')->item(0);
        $scope = $sectPr instanceof \DOMElement ? $sectPr : $bodyEl;
        $headerTypes = $this->resolveHeaderTypes($scope, $pkg, isFooter: false);
        $footerTypes = $this->resolveHeaderTypes($scope, $pkg, isFooter: true);
        // Word shows the first-page parts only with <w:titlePg/>, the
        // even-page ones only with <w:evenAndOddHeaders/> in the settings.
        $usesFirst = $sectPr instanceof \DOMElement && self::onFlag($sectPr, 'titlePg');
        $usesEven = $pkg->settingsXml?->documentElement instanceof \DOMElement
            && self::onFlag($pkg->settingsXml->documentElement, 'evenAndOddHeaders');

        $headersBy = [
            'default' => [],
            'first' => [],
            'even' => [],
        ];
        $footersBy = [
            'default' => [],
            'first' => [],
            'even' => [],
        ];
        $watermarkText = null;

        foreach ($pkg->headers as $partPath => $headerDoc) {
            $type = $headerTypes[$partPath] ?? null;
            // A watermark usually sits in the default header; extract it from any
            // one (the first found wins) so that HTML does not get duplicates.
            $watermarkText ??= (new WatermarkExtractor)->extract($headerDoc);

            $headerRoot = $headerDoc->documentElement;
            if ($type === null || ! $headerRoot instanceof \DOMElement) {
                continue;
            }
            $hImg = new ImageReader($pkg, $partPath);
            $hReader = new BodyReader($styles, $numbering, $hImg, $pkg, $partPath);
            $headersBy[$type] = $this->ensureBlocks($hReader->read($headerRoot));
        }

        foreach ($pkg->footers as $partPath => $footerDoc) {
            $type = $footerTypes[$partPath] ?? null;
            $footerRoot = $footerDoc->documentElement;
            if ($type === null || ! $footerRoot instanceof \DOMElement) {
                continue;
            }
            $fImg = new ImageReader($pkg, $partPath);
            $fReader = new BodyReader($styles, $numbering, $fImg, $pkg, $partPath);
            $footersBy[$type] = $this->ensureBlocks($fReader->read($footerRoot));
        }

        return new Document(
            section: new Section(
                body: $this->ensureBlocks($body),
                header: $headersBy['default'],
                footer: $footersBy['default'],
                pageSetup: $pageSetup,
                firstHeader: $usesFirst ? $headersBy['first'] : [],
                firstFooter: $usesFirst ? $footersBy['first'] : [],
                evenHeader: $usesEven ? $headersBy['even'] : [],
                evenFooter: $usesEven ? $footersBy['even'] : [],
            ),
            watermarkText: $watermarkText,
        );
    }

    /**
     * Parses `<w:headerReference>`/`<w:footerReference>` in sectPr and returns
     * a map<partPath, type>.
     *
     * @return array<string, string>
     */
    private function resolveHeaderTypes(\DOMElement $body, DocxPackage $pkg, bool $isFooter): array
    {
        $tagName = $isFooter ? 'footerReference' : 'headerReference';
        $refs = $body->getElementsByTagNameNS(OoxmlNs::W, $tagName);
        $out = [];
        foreach ($refs as $ref) {
            if (! $ref instanceof \DOMElement) {
                continue;
            }
            $type = $ref->getAttributeNS(OoxmlNs::W, 'type');
            if ($type === '') {
                $type = 'default';
            }
            $rId = $ref->getAttributeNS(OoxmlNs::R, 'id');
            if ($rId === '') {
                continue;
            }
            try {
                $rel = $pkg->resolveDocumentRel($rId);
            } catch (\Throwable) {
                continue;
            }
            $absPath = $pkg->resolveMediaPath($pkg->documentPartPath, $rel->target);
            $out[$absPath] = $type;
        }

        return $out;
    }

    /** An on/off element among the children: present and not `w:val="0|false|off"`. */
    private static function onFlag(\DOMElement $parent, string $name): bool
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === OoxmlNs::W && $child->localName === $name) {
                return ! in_array(strtolower($child->getAttributeNS(OoxmlNs::W, 'val')), ['0', 'false', 'off'], true);
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<BlockElement>
     */
    private function ensureBlocks(array $items): array
    {
        return array_values(array_filter(
            $items,
            fn ($i): bool => $i instanceof BlockElement,
        ));
    }
}
