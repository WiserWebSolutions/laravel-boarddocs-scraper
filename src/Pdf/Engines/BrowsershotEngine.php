<?php

namespace BoardDocsScraper\Pdf\Engines;

use BoardDocsScraper\Exceptions\BoardDocsException;
use BoardDocsScraper\Pdf\AgendaHtml;
use BoardDocsScraper\Pdf\Assembler;
use BoardDocsScraper\Pdf\MeetingDocument;
use BoardDocsScraper\Pdf\PdfEngine;
use BoardDocsScraper\Pdf\RenderedPdf;
use setasign\Fpdi\PdfParser\StreamReader;
use Spatie\Browsershot\Browsershot;

/**
 * Optional engine: renders the agenda with headless Chrome (spatie/browsershot)
 * for higher HTML/CSS fidelity, then merges attachment PDFs with FPDI.
 *
 * Note: this engine does NOT rewrite remote BoardDocs links into internal
 * anchors — that requires the tcpdf engine. Attachments are still merged inline
 * with bookmarks and non-PDF files are embedded.
 */
class BrowsershotEngine implements PdfEngine
{
    public function __construct(protected array $config = [])
    {
    }

    public function render(MeetingDocument $document): RenderedPdf
    {
        if (! class_exists(Browsershot::class)) {
            throw new BoardDocsException(
                "The 'browsershot' PDF engine requires spatie/browsershot ".
                '(composer require spatie/browsershot) plus Node and Chromium.'
            );
        }

        $agendaBytes = $this->renderAgenda($document);

        $pdf = Assembler::newPdf($document);
        $count = $pdf->setSourceFile(StreamReader::createByString($agendaBytes));
        for ($p = 1; $p <= $count; $p++) {
            $tpl = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tpl);
            $orient = ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P';
            $pdf->AddPage($orient, [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
        }
        $pdf->Bookmark('Agenda', 0, 0, 1);

        $toc = [['title' => 'Agenda', 'page' => 1]];
        $tempFiles = [];

        if ($document->selfContained() && ! empty($document->savedAttachments)) {
            $info = Assembler::probe($document->savedAttachments);
            [, $attachmentToc] = Assembler::append(
                $pdf,
                $document->savedAttachments,
                $info,
                $document->embedNonPdf(),
                $tempFiles,
            );
            $toc = array_merge($toc, $attachmentToc);
        }

        $pageCount = $pdf->getPage();
        $bytes = $pdf->Output($document->filename, 'S');

        Assembler::cleanup($tempFiles);

        return new RenderedPdf($bytes, $pageCount, $toc);
    }

    protected function renderAgenda(MeetingDocument $document): string
    {
        $html = AgendaHtml::document($document->agendaHtml, $document->baseUrl);

        $shot = Browsershot::html($html)
            ->format('Letter')
            ->showBackground()
            ->margins(12, 12, 12, 12);

        if (! empty($this->config['node_binary'])) {
            $shot->setNodeBinary($this->config['node_binary']);
        }
        if (! empty($this->config['npm_binary'])) {
            $shot->setNpmBinary($this->config['npm_binary']);
        }
        if (! empty($this->config['chrome_path'])) {
            $shot->setChromePath($this->config['chrome_path']);
        }

        return $shot->pdf();
    }
}
