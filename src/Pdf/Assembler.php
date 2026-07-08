<?php

namespace BoardDocsScraper\Pdf;

use BoardDocsScraper\Data\SavedAttachment;
use BoardDocsScraper\Support\Urls;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Shared PDF assembly helpers used by both engines: probing attachment PDFs,
 * appending them with bookmarks, and building an embedded-files page for
 * non-PDF (or unparseable) attachments.
 */
class Assembler
{
    /**
     * Build a configured TCPDF/FPDI instance from a MeetingDocument's options.
     */
    public static function newPdf(MeetingDocument $d): Fpdi
    {
        $format = strtoupper((string) $d->option('page_size', 'LETTER'));
        $orientation = strtoupper((string) $d->option('orientation', 'P'));
        $orientation = $orientation !== '' ? $orientation[0] : 'P';
        $m = (array) $d->option('margins', ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15]);

        $pdf = new Fpdi($orientation, 'mm', $format, true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('laravel-boarddocs-scraper');
        $pdf->SetAuthor('BoardDocs Scraper');
        $pdf->SetTitle(trim($d->title.' — '.$d->date));
        $pdf->SetSubject($d->committee);
        $pdf->SetMargins((float) ($m['left'] ?? 15), (float) ($m['top'] ?? 15), (float) ($m['right'] ?? 15));
        $pdf->SetAutoPageBreak(true, (float) ($m['bottom'] ?? 15));
        $pdf->SetFont('helvetica', '', 10);

        return $pdf;
    }

    /**
     * Determine which saved attachments are importable PDFs and their page counts.
     *
     * @param  SavedAttachment[]  $saved
     * @return array<int, array{importable:bool, count:int}>
     */
    public static function probe(array $saved): array
    {
        $info = [];
        foreach ($saved as $i => $att) {
            $info[$i] = ['importable' => false, 'count' => 0];
            if (! $att->isPdf()) {
                continue;
            }
            try {
                $probe = new Fpdi;
                $count = $probe->setSourceFile(StreamReader::createByString($att->blob));
                $info[$i] = ['importable' => $count > 0, 'count' => (int) $count];
            } catch (\Throwable $e) {
                $info[$i] = ['importable' => false, 'count' => 0];
            }
        }

        return $info;
    }

    /**
     * Append attachments to $pdf: merge importable PDFs inline (with a bookmark
     * per attachment) and list the rest on a single embedded-files page.
     *
     * @param  SavedAttachment[]  $saved
     * @param  array<int, array{importable:bool, count:int}>  $info
     * @param  array<int, string>  $tempFiles  (by ref) temp paths to clean up after Output
     * @return array{0: array<int,int>, 1: array<int, array{title:string,page:int}>}  [pageForIndex, toc]
     */
    public static function append(Fpdi $pdf, array $saved, array $info, bool $embedNonPdf, array &$tempFiles): array
    {
        $pageForIndex = [];
        $toc = [];

        // Merge importable PDF attachments.
        foreach ($saved as $i => $att) {
            if (! ($info[$i]['importable'] ?? false)) {
                continue;
            }
            try {
                $count = $pdf->setSourceFile(StreamReader::createByString($att->blob));
                for ($p = 1; $p <= $count; $p++) {
                    $tpl = $pdf->importPage($p);
                    $size = $pdf->getTemplateSize($tpl);
                    $orient = ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P';
                    $pdf->AddPage($orient, [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                    if ($p === 1) {
                        $page = $pdf->getPage();
                        $pageForIndex[$i] = $page;
                        $pdf->Bookmark($att->bookmark, 0, 0, $page);
                        $toc[] = ['title' => $att->bookmark, 'page' => $page];
                    }
                }
            } catch (\Throwable $e) {
                // Could not merge after all — fall back to embedding.
                $info[$i]['importable'] = false;
            }
        }

        // Anything not merged becomes an embedded file (if enabled).
        $embeds = array_values(array_filter(
            array_keys($info),
            fn ($i) => ! ($info[$i]['importable'] ?? false)
        ));

        if ($embedNonPdf && ! empty($embeds)) {
            $pdf->AddPage();
            $embedPage = $pdf->getPage();
            $pdf->SetFont('helvetica', 'B', 13);
            $pdf->Cell(0, 10, 'Embedded Attachments', 0, 1);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Ln(2);

            foreach ($embeds as $i) {
                $att = $saved[$i];
                $path = self::writeTemp($att, $tempFiles);
                try {
                    $y = $pdf->GetY();
                    $pdf->Annotation(12, $y, 6, 6, $att->bookmark, [
                        'Subtype' => 'FileAttachment',
                        'Name' => 'Paperclip',
                        'FS' => $path,
                    ]);
                } catch (\Throwable $e) {
                    // Embedding is best-effort; keep the listing regardless.
                }
                $pdf->Cell(6, 7, '', 0, 0);
                $pdf->Cell(0, 7, $att->bookmark, 0, 1);

                $pageForIndex[$i] = $embedPage;
                $pdf->Bookmark($att->bookmark, 0, 0, $embedPage);
                $toc[] = ['title' => $att->bookmark, 'page' => $embedPage];
            }
        }

        return [$pageForIndex, $toc];
    }

    /**
     * Predict the destination page of every attachment before assembly, so the
     * agenda's links can be rewritten to point at them.
     *
     * @param  SavedAttachment[]  $saved
     * @param  array<int, array{importable:bool, count:int}>  $info
     * @return array<int, int>  saved-index => 1-based page
     */
    public static function predictPages(int $agendaPageCount, array $saved, array $info, bool $embedNonPdf): array
    {
        $pageForIndex = [];
        $cursor = $agendaPageCount;

        foreach ($saved as $i => $att) {
            if ($info[$i]['importable'] ?? false) {
                $pageForIndex[$i] = $cursor + 1;
                $cursor += (int) $info[$i]['count'];
            }
        }

        $hasEmbeds = false;
        foreach ($saved as $i => $att) {
            if (! ($info[$i]['importable'] ?? false)) {
                $hasEmbeds = true;
                break;
            }
        }

        if ($embedNonPdf && $hasEmbeds) {
            $embedPage = $cursor + 1;
            foreach ($saved as $i => $att) {
                if (! ($info[$i]['importable'] ?? false)) {
                    $pageForIndex[$i] = $embedPage;
                }
            }
        }

        return $pageForIndex;
    }

    /**
     * @param  array<int, string>  $tempFiles  (by ref)
     */
    protected static function writeTemp(SavedAttachment $att, array &$tempFiles): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bdscraper_'.bin2hex(random_bytes(5));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = Urls::sanitizePathComponent($att->bookmark);
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $att->blob);
        $tempFiles[] = $path;

        return $path;
    }

    /**
     * @param  array<int, string>  $tempFiles
     */
    public static function cleanup(array $tempFiles): void
    {
        $dirs = [];
        foreach ($tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
            $dirs[dirname($file)] = true;
        }
        foreach (array_keys($dirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }
}
