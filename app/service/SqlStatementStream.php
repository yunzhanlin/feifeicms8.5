<?php
declare(strict_types=1);

namespace app\service;

final class SqlStatementStream
{
    /** @return \Generator<int, string> */
    public function fromFile(string $path, bool $requireTerminator = false): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('无法读取 SQL 文件：' . $path);
        $buffer = '';
        $quote = null;
        $escaped = false;
        try {
            while (($character = fgetc($handle)) !== false) {
                $buffer .= $character;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($quote !== null && $character === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($quote !== null && $character === $quote) {
                    $next = fgetc($handle);
                    if ($next === $quote) {
                        $buffer .= $next;
                        continue;
                    }
                    if ($next !== false) fseek($handle, -1, SEEK_CUR);
                    $quote = null;
                    continue;
                }
                if ($quote === null && ($character === "'" || $character === '"' || $character === '`')) {
                    $quote = $character;
                    continue;
                }
                if ($quote === null && $character === ';') {
                    $statement = trim(substr($buffer, 0, -1));
                    if ($statement !== '') yield $statement;
                    $buffer = '';
                }
            }
        } finally {
            fclose($handle);
        }
        $remainder = trim($buffer);
        if ($remainder !== '') {
            if ($requireTerminator) throw new \RuntimeException('备份 SQL 不完整');
            yield $remainder;
        }
    }
}
