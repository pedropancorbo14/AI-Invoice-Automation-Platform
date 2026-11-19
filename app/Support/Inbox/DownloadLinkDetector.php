<?php

namespace App\Support\Inbox;

use DOMDocument;
use Illuminate\Support\Str;

/**
 * Encuentra enlaces que parecen de descarga de una factura (portal del
 * proveedor, "Descargar factura", enlaces a .pdf...).
 */
class DownloadLinkDetector
{
    private const KEYWORDS = [
        'factura', 'invoice', 'descarga', 'descargar', 'download', 'pdf',
        'recibo', 'receipt', 'documento', 'bill', 'albaran', 'albarán',
    ];

    /** Enlaces que nunca son la factura. */
    private const NOISE = [
        'unsubscribe', 'baja', 'privacy', 'privacidad', 'facebook.com', 'twitter.com',
        'x.com/', 'instagram.com', 'linkedin.com', 'youtube.com', 'tiktok.com',
    ];

    /**
     * @return list<string>
     */
    public function detect(string $text, string $html): array
    {
        $links = [...$this->fromHtml($html), ...$this->fromText($text)];

        return array_values(array_unique($links));
    }

    /**
     * @return list<string>
     */
    private function fromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR);

        $links = [];

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $url = trim($anchor->getAttribute('href'));

            if ($this->isCandidate($url, $anchor->textContent.' '.$anchor->getAttribute('title'))) {
                $links[] = $url;
            }
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    private function fromText(string $text): array
    {
        preg_match_all('#https?://[^\s<>"\')\]]+#i', $text, $matches, PREG_OFFSET_CAPTURE);

        $links = [];

        foreach ($matches[0] as [$url, $offset]) {
            $context = substr($text, max(0, $offset - 80), min(80, $offset));

            if ($this->isCandidate(rtrim($url, '.,;:'), $context)) {
                $links[] = rtrim($url, '.,;:');
            }
        }

        return $links;
    }

    private function isCandidate(string $url, string $context): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $haystack = Str::lower($url.' '.$context);

        if (Str::contains($haystack, self::NOISE)) {
            return false;
        }

        return Str::contains($haystack, self::KEYWORDS);
    }
}
