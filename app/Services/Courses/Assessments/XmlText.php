<?php

declare(strict_types=1);

namespace App\Services\Courses\Assessments;

use DOMDocument;
use DOMXPath;

/**
 * Safe XML loading and HTML-to-text for quiz files from other systems.
 * No external entities, no network, and nothing is ever written to disk.
 */
final class XmlText
{
    /** Loads XML text, or returns null when it is not well-formed or declares entities. */
    public static function load(string $xml): ?DOMDocument
    {
        $xml = str_replace("\xEF\xBB\xBF", '', $xml);

        // Entity declarations are how XML bombs and external reads are made; quiz exports never need them.
        if (stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_BIGLINES);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $doc : null;
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        return new DOMXPath($doc);
    }

    /**
     * The visible text of a fragment of HTML (or plain text): paragraphs and
     * breaks become line breaks, tags are dropped, entities decoded.
     */
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('~<(script|style)\b.*?</\1>~is', '', $html);
        $html = (string) preg_replace('~<br\s*/?>|</(p|div|li|tr|h\d)>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace('/\s*\n\s*/u', "\n", $text);

        return trim($text);
    }
}
