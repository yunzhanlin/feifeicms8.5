<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** FeiFeiCMS 4.3/7.4 compatible, bidirectional URL-rule compiler. */
final class LegacyRewriteRules
{
    /** @var array<string,string> */
    private const TOKENS = [
        '(:num)' => '[0-9]+',
        '(:letter)' => '[A-Za-z]+',
        '(:letternum)' => '[A-Za-z0-9]+',
        '(:any)' => '[^/?#]+',
    ];

    /** @return list<array{line:int,source:string,target:string,extra:array<string,string>,source_regex:string,target_regex:string}> */
    public function parse(string $definition): array
    {
        $rules = [];
        $sourcePatterns = [];
        $targetPatterns = [];
        foreach (preg_split('/\R/u', $definition) ?: [] as $offset => $rawLine) {
            $lineNo = $offset + 1;
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) continue;
            $parts = array_map('trim', explode('===', $line));
            if (!in_array(count($parts), [2, 3], true) || $parts[0] === '' || $parts[1] === '') {
                throw new InvalidArgumentException("第 {$lineNo} 行格式错误，应为“默认 URL===自定义 URL”或追加第三段固定参数。");
            }
            $source = trim($parts[0], '/');
            $target = trim($parts[1], '/');
            $this->assertSource($source, $lineNo);
            $this->assertTarget($target, $lineNo);
            $sourceTokens = $this->tokens($source);
            $targetTokens = $this->tokens($target);
            if ($sourceTokens !== $targetTokens) {
                throw new InvalidArgumentException("第 {$lineNo} 行两侧占位符的类型或顺序不一致。");
            }
            $sourceRegex = $this->regex($source);
            $targetRegex = $this->regex($target);
            if (isset($sourcePatterns[$sourceRegex])) {
                throw new InvalidArgumentException("第 {$lineNo} 行默认 URL 与第 {$sourcePatterns[$sourceRegex]} 行冲突。");
            }
            if (isset($targetPatterns[$targetRegex])) {
                throw new InvalidArgumentException("第 {$lineNo} 行自定义 URL 与第 {$targetPatterns[$targetRegex]} 行冲突。");
            }
            $sourcePatterns[$sourceRegex] = $lineNo;
            $targetPatterns[$targetRegex] = $lineNo;
            $rules[] = [
                'line' => $lineNo,
                'source' => $source,
                'target' => $target,
                'extra' => $this->extra($parts[2] ?? '', $lineNo),
                'source_regex' => $sourceRegex,
                'target_regex' => $targetRegex,
            ];
        }
        return $rules;
    }

    public function rewrite(string $action, string $definition): ?string
    {
        return $this->matchRewrite([$action], $definition)['path'] ?? null;
    }

    /** @param list<string> $actions @return array{path:string,action:string,line:int}|null */
    public function matchRewrite(array $actions, string $definition): ?array
    {
        $rules = $this->parse($definition);
        foreach ($actions as $action) {
            foreach ($rules as $rule) {
                if (!preg_match($rule['source_regex'], trim($action, '/'), $matches)) continue;
                return ['path' => $this->replaceTokens($rule['target'], array_slice($matches, 1)), 'action' => $action, 'line' => $rule['line']];
            }
        }
        return null;
    }

    public function resolve(string $path, string $definition): ?string
    {
        return $this->resolveRoute($path, $definition)['action'] ?? null;
    }

    /** @return array{action:string,module:string,operation:string,params:array<string,string>,line:int}|null */
    public function resolveRoute(string $path, string $definition): ?array
    {
        foreach ($this->parse($definition) as $rule) {
            if (!preg_match($rule['target_regex'], trim(rawurldecode($path), '/'), $matches)) continue;
            $captures = array_map(static fn (string $value): string => rawurldecode($value), array_slice($matches, 1));
            $action = $this->replaceTokens($rule['source'], $captures, false);
            $parts = explode('-', $rule['source']);
            $module = strtolower((string) array_shift($parts));
            $operation = strtolower((string) array_shift($parts));
            $params = $rule['extra'];
            $capture = 0;
            while (count($parts) >= 2) {
                $key = strtolower((string) array_shift($parts));
                $templateValue = (string) array_shift($parts);
                $params[$key] = isset(self::TOKENS[$templateValue]) ? (string) ($captures[$capture++] ?? '') : $templateValue;
            }
            return compact('action', 'module', 'operation', 'params') + ['line' => $rule['line']];
        }
        return null;
    }

    /** @return array{rewrite_rules:array<string,array{find:string,replace:string,line:int}>,route_rules:list<array{pattern:string,action:string,extra:array<string,string>,line:int}>} */
    public function compiled(string $definition): array
    {
        $rewrite = [];
        $routes = [];
        foreach ($this->parse($definition) as $rule) {
            $rewrite[$rule['source']] = ['find' => $rule['source_regex'], 'replace' => $rule['target'], 'line' => $rule['line']];
            $routes[] = ['pattern' => $rule['target_regex'], 'action' => $rule['source'], 'extra' => $rule['extra'], 'line' => $rule['line']];
        }
        return ['rewrite_rules' => $rewrite, 'route_rules' => $routes];
    }

    /** @return list<array{line:int,source:string,target:string,sample_source:string,sample_target:string}> */
    public function preview(string $definition): array
    {
        $samples = ['(:num)' => '12', '(:letter)' => 'movies', '(:letternum)' => 'movie12', '(:any)' => 'sample'];
        return array_map(static fn (array $rule): array => [
            'line' => $rule['line'], 'source' => $rule['source'], 'target' => $rule['target'],
            'sample_source' => strtr($rule['source'], $samples), 'sample_target' => strtr($rule['target'], $samples),
        ], $this->parse($definition));
    }

    private function assertSource(string $value, int $line): void
    {
        $this->assertSafe($value, $line, '默认 URL');
        if (!preg_match('/^[a-z][a-z0-9_]*-[a-z][a-z0-9_]*(?:-[a-z][a-z0-9_]*-(?:\(:num\)|\(:letter\)|\(:letternum\)|\(:any\)|[A-Za-z0-9_.]+))*$/i', $value)) {
            throw new InvalidArgumentException("第 {$line} 行默认 URL 不是 FeiFeiCMS 的 模块-操作-参数-值 格式。");
        }
    }

    private function assertTarget(string $value, int $line): void
    {
        $this->assertSafe($value, $line, '自定义 URL');
        if (str_contains($value, '..') || str_contains($value, '\\') || str_contains($value, '?') || str_contains($value, '#') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
            throw new InvalidArgumentException("第 {$line} 行自定义 URL 必须是站内相对路径。");
        }
    }

    private function assertSafe(string $value, int $line, string $side): void
    {
        $withoutTokens = str_replace(array_keys(self::TOKENS), '', $value);
        if ($value === '' || strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F]/', $value) || preg_match('/[^A-Za-z0-9_\-\/.]/', $withoutTokens)) {
            throw new InvalidArgumentException("第 {$line} 行{$side}含有不支持的字符。");
        }
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        preg_match_all('/\(:num\)|\(:letter\)|\(:letternum\)|\(:any\)/', $value, $matches);
        return $matches[0] ?? [];
    }

    private function regex(string $value): string
    {
        $quoted = preg_quote($value, '~');
        foreach (self::TOKENS as $token => $pattern) $quoted = str_replace(preg_quote($token, '~'), '(' . $pattern . ')', $quoted);
        return '~^' . $quoted . '$~uD';
    }

    /** @return array<string,string> */
    private function extra(string $value, int $line): array
    {
        if (trim($value) === '') return [];
        parse_str(ltrim(trim($value), '?'), $parsed);
        $result = [];
        foreach ($parsed as $key => $item) {
            if (!is_scalar($item) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $key)) throw new InvalidArgumentException("第 {$line} 行附加参数格式错误。");
            $result[(string) $key] = mb_substr((string) $item, 0, 200);
        }
        return $result;
    }

    /** @param list<string> $captures */
    private function replaceTokens(string $template, array $captures, bool $encode = true): string
    {
        $index = 0;
        return (string) preg_replace_callback('/\(:num\)|\(:letter\)|\(:letternum\)|\(:any\)/', static function () use (&$index, $captures, $encode): string {
            $value = (string) ($captures[$index++] ?? '');
            return $encode ? rawurlencode(rawurldecode($value)) : $value;
        }, $template);
    }
}
