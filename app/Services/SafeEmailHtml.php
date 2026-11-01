<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

class SafeEmailHtml
{
    public function clean(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        if (! $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Impossible de traiter la version HTML du message.');
        }
        $xpath = new DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//*')) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            if (in_array(strtolower($element->tagName), ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'meta', 'base', 'link', 'svg', 'math', 'video', 'audio', 'source', 'template'], true)) {
                $element->parentNode?->removeChild($element);
                continue;
            }
            for ($i = $element->attributes->length - 1; $i >= 0; $i--) {
                $attribute = $element->attributes->item($i);
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on') || in_array($name, ['href', 'srcset', 'action', 'formaction', 'xlink:href', 'background'], true)
                    || ($name === 'src' && ! (strtolower($element->tagName) === 'img' && preg_match('/\Adata:image\/(png|jpeg|gif|webp);base64,/i', $attribute->value)))) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }
        $result = $document->saveHTML();
        if ($result === false) {
            throw new RuntimeException('Impossible de traiter la version HTML du message.');
        }

        return $result;
    }
}
