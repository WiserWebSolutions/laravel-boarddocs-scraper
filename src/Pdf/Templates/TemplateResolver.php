<?php

namespace BoardDocsScraper\Pdf\Templates;

use BoardDocsScraper\Exceptions\BoardDocsException;

/**
 * Resolves config('boarddocs.pdf.template') into a PdfTemplate instance.
 * Accepts the built-in key ("default"), a key registered under
 * config('boarddocs.pdf.templates'), a fully-qualified PdfTemplate class
 * name, or an already-constructed PdfTemplate instance.
 */
class TemplateResolver
{
    /**
     * @param  array<string, mixed>  $pdfOptions  the config('boarddocs.pdf') array
     */
    public static function resolve(array $pdfOptions): PdfTemplate
    {
        $template = $pdfOptions['template'] ?? 'default';

        if ($template instanceof PdfTemplate) {
            return $template;
        }

        if (! is_string($template)) {
            throw new BoardDocsException('boarddocs.pdf.template must be a string key, a PdfTemplate class name, or a PdfTemplate instance.');
        }

        $registered = (array) ($pdfOptions['templates'] ?? []);
        $class = $registered[$template] ?? ($template === 'default' ? DefaultTemplate::class : $template);

        if (! class_exists($class) || ! is_subclass_of($class, PdfTemplate::class)) {
            throw new BoardDocsException("Unknown PDF template [{$template}]. Register it under config('boarddocs.pdf.templates') or pass a PdfTemplate class name.");
        }

        return new $class;
    }
}
