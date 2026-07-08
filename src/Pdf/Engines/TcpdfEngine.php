<?php

namespace BoardDocsScraper\Pdf\Engines;

use BoardDocsScraper\Pdf\AgendaHtml;
use BoardDocsScraper\Pdf\Assembler;
use BoardDocsScraper\Pdf\LinkRewriter;
use BoardDocsScraper\Pdf\MeetingDocument;
use BoardDocsScraper\Pdf\PdfEngine;
use BoardDocsScraper\Pdf\RenderedPdf;

/**
 * Default engine: renders the agenda with TCPDF's writeHTML, merges attachment
 * PDFs with FPDI, and rewrites remote BoardDocs links into internal PDF page
 * links. This is the only engine that can perform the link remap in pure PHP.
 *
 * Strategy: the agenda's page count depends only on the agenda HTML (not on the
 * link href values), so we (1) render the agenda once to count its pages,
 * (2) probe the attachments to compute their destination pages, (3) rewrite the
 * agenda links to numeric "#<page>" anchors, then (4) render for real and append
 * the attachments at exactly those pages.
 */
class TcpdfEngine implements PdfEngine
{
    public function render(MeetingDocument $document): RenderedPdf
    {
        $saved = $document->savedAttachments;
        $cleanAgenda = AgendaHtml::clean($document->agendaHtml);

        // (1) Agenda page count (layout is independent of link href values).
        $agendaPages = $this->agendaPageCount($document, $cleanAgenda);

        // (2) Probe attachments and predict their destination pages.
        $info = ($document->selfContained() && ! empty($saved)) ? Assembler::probe($saved) : [];
        $predicted = ! empty($info)
            ? Assembler::predictPages($agendaPages, $saved, $info, $document->embedNonPdf())
            : [];

        // (3) Rewrite BoardDocs links to internal page anchors.
        $agendaFragment = $cleanAgenda;
        if ($document->remapLinks() && ! empty($predicted)) {
            [$agendaFragment] = LinkRewriter::rewrite(
                $cleanAgenda,
                $saved,
                $document->baseUrl,
                $document->site,
                $predicted,
            );
        }

        // (4) Render for real.
        $pdf = Assembler::newPdf($document);
        $pdf->AddPage();
        $pdf->writeHTML(AgendaHtml::styleBlock()."\n".$agendaFragment, true, false, true, false, '');
        $pdf->Bookmark('Agenda', 0, 0, 1);

        $toc = [['title' => 'Agenda', 'page' => 1]];
        $tempFiles = [];

        if ($document->selfContained() && ! empty($saved)) {
            [, $attachmentToc] = Assembler::append($pdf, $saved, $info, $document->embedNonPdf(), $tempFiles);
            $toc = array_merge($toc, $attachmentToc);
        }

        $pageCount = $pdf->getPage();
        $bytes = $pdf->Output($document->filename, 'S');

        Assembler::cleanup($tempFiles);

        return new RenderedPdf($bytes, $pageCount, $toc);
    }

    /**
     * Render the agenda alone to determine how many pages it occupies.
     */
    protected function agendaPageCount(MeetingDocument $document, string $cleanAgenda): int
    {
        $pdf = Assembler::newPdf($document);
        $pdf->AddPage();
        $pdf->writeHTML(AgendaHtml::styleBlock()."\n".$cleanAgenda, true, false, true, false, '');

        return $pdf->getPage();
    }
}
