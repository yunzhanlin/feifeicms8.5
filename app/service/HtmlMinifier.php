<?php
declare(strict_types=1);

namespace app\service;

/** Conservative HTML whitespace reduction; never rewrites raw text or quoted values. */
final class HtmlMinifier
{
    public function minify(string $html): string
    {
        $length = strlen($html);
        $offset = 0;
        $output = '';
        $preserved = [];
        while ($offset < $length) {
            if ($html[$offset] !== '<') {
                $end = strpos($html, '<', $offset);
                if ($end === false) $end = $length;
                $text = substr($html, $offset, $end - $offset);
                $output .= $preserved === [] ? $this->spaces($text) : $text;
                $offset = $end;
                continue;
            }
            foreach (['<!--' => '-->', '<![CDATA[' => ']]>'] as $opening => $closing) {
                if (substr_compare($html, $opening, $offset, strlen($opening)) !== 0) continue;
                $end = strpos($html, $closing, $offset + strlen($opening));
                if ($end === false) return $output . substr($html, $offset);
                $end += strlen($closing);
                $output .= substr($html, $offset, $end - $offset);
                $offset = $end;
                continue 2;
            }
            $isTag = preg_match('~\G<(/?)([a-z][a-z0-9:-]*)(?=[ \t\r\n\f/>])~i', $html, $match, 0, $offset) === 1;
            if (!$isTag && !in_array(substr($html, $offset, 2), ['<!', '<?'], true)) {
                $output .= '<';
                ++$offset;
                continue;
            }
            $end = $this->tagEnd($html, $offset, $length);
            if ($end === null) return $output . substr($html, $offset);
            $token = substr($html, $offset, $end - $offset);
            $tag = $isTag ? strtolower($match[2]) : '';
            $closing = $isTag && $match[1] === '/';
            if (!$closing && $tag === 'plaintext') return $output . substr($html, $offset);
            if (!$closing && in_array($tag, ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes'], true)) {
                // Raw-text elements may contain literal '<', '>' and fake HTML tags.
                if (!preg_match('~</' . $tag . '(?=[ \t\r\n\f/>])~i', $html, $close, PREG_OFFSET_CAPTURE, $end)) {
                    return $output . substr($html, $offset);
                }
                $rawEnd = $this->tagEnd($html, $close[0][1], $length);
                if ($rawEnd === null) return $output . substr($html, $offset);
                $output .= substr($html, $offset, $rawEnd - $offset);
                $offset = $rawEnd;
                continue;
            }
            $protectedTag = in_array($tag, ['pre', 'code', 'listing', 'svg', 'math'], true);
            $output .= $preserved !== [] || $protectedTag ? $token : $this->tagSpaces($token);
            if ($protectedTag) {
                if ($closing) {
                    if (isset($preserved[$tag]) && --$preserved[$tag] === 0) unset($preserved[$tag]);
                } elseif (!in_array($tag, ['svg', 'math'], true) || !str_ends_with(rtrim($token), '/>')) {
                    $preserved[$tag] = ($preserved[$tag] ?? 0) + 1;
                }
            }
            $offset = $end;
        }
        return $output;
    }

    private function spaces(string $text): string
    {
        return preg_replace('/[ \t\r\n\f]+/', ' ', $text) ?? $text;
    }

    private function tagSpaces(string $tag): string
    {
        return preg_replace_callback('~"[^"]*"|\'[^\']*\'|[ \t\r\n\f]+~s', static fn (array $match): string =>
            $match[0][0] === '"' || $match[0][0] === "'" ? $match[0] : ' ', $tag) ?? $tag;
    }

    /** Return the position after '>', respecting quotes in attribute values. */
    private function tagEnd(string $html, int $offset, int $length): ?int
    {
        $quote = '';
        for ($index = $offset + 1; $index < $length; ++$index) {
            $character = $html[$index];
            if ($quote !== '') {
                if ($character === $quote) $quote = '';
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                return $index + 1;
            }
        }
        return null;
    }
}
