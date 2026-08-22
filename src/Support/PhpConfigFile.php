<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Exceptions\ConfigWriteException;
use ParseError;

/**
 * Edits a published Laravel config file in place, by dot path.
 *
 * Rewriting the array with `var_export()` would throw away every comment the
 * file ships with, so instead the source is tokenised and only the tokens that
 * make up the target value are replaced — the rest of the file, comments and
 * formatting included, is byte-for-byte untouched.
 *
 * The whole class is built to fail closed. A path it cannot locate
 * unambiguously is refused rather than guessed at, the edited source is parsed
 * again before it is written, and the write itself is atomic (temp file plus
 * rename) so an interrupted run can never leave a half-written config behind.
 */
final class PhpConfigFile
{
    private const INDENT = '    ';

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function contents(): string
    {
        if (! $this->exists()) {
            throw ConfigWriteException::unwritable($this->path);
        }

        return (string) file_get_contents($this->path);
    }

    /**
     * The source text of a value, or null when the path is not in the file.
     */
    public function sourceOf(string $path): ?string
    {
        $source = $this->contents();
        $entry = $this->map($source)[$path] ?? null;

        return $entry === null ? null : substr($source, $entry['start'], $entry['end'] - $entry['start']);
    }

    /**
     * Apply changes to the source without touching the file.
     *
     * @param  array<string, mixed>  $values  Dot path => value.
     * @return array{source: string, changes: list<array{key: string, from: string|null, to: string}>}
     */
    public function edit(array $values): array
    {
        $source = $this->contents();
        $changes = [];

        foreach ($values as $path => $value) {
            $exported = PhpValue::export($value, substr_count($path, '.') + 1);
            $map = $this->map($source);
            $entry = $map[$path] ?? null;

            if ($entry !== null) {
                $from = substr($source, $entry['start'], $entry['end'] - $entry['start']);

                if ($from === $exported) {
                    continue;
                }

                $source = substr_replace($source, $exported, $entry['start'], $entry['end'] - $entry['start']);
                $changes[] = ['key' => $path, 'from' => $from, 'to' => $exported];

                continue;
            }

            $source = $this->insert($source, $map, $path, $value);
            $changes[] = ['key' => $path, 'from' => null, 'to' => $exported];
        }

        if ($changes !== []) {
            $this->assertParses($source, array_keys($values));
        }

        return ['source' => $source, 'changes' => $changes];
    }

    /**
     * Write new contents atomically, keeping a copy of the previous file.
     */
    public function write(string $source, ?string $backupDirectory = null): ?string
    {
        $backup = null;

        if ($backupDirectory !== null && $this->exists()) {
            $backup = $this->backup($backupDirectory);
        }

        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, $source) === false) {
            throw ConfigWriteException::unwritable($this->path);
        }

        $mode = @fileperms($this->path);

        if (is_int($mode)) {
            @chmod($temporary, $mode & 0777);
        }

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw ConfigWriteException::unwritable($this->path);
        }

        return $backup;
    }

    private function backup(string $directory): string
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw ConfigWriteException::unwritable($directory);
        }

        $target = rtrim($directory, '/').'/'.basename($this->path).'.'.date('Ymd-His').'.bak';

        if (! @copy($this->path, $target)) {
            throw ConfigWriteException::unwritable($target);
        }

        return $target;
    }

    /**
     * Add a key that is not in the file yet, creating any missing levels below
     * the deepest array that does exist.
     *
     * @param  array<string, array{start: int, end: int, isArray: bool, bodyStart: int, bodyEnd: int}>  $map
     */
    private function insert(string $source, array $map, string $path, mixed $value): string
    {
        $segments = explode('.', $path);
        $ancestor = '';
        $remainder = $segments;

        for ($length = count($segments) - 1; $length >= 1; $length--) {
            $candidate = implode('.', array_slice($segments, 0, $length));

            if (isset($map[$candidate])) {
                if (! $map[$candidate]['isArray']) {
                    throw ConfigWriteException::pathNotEditable($path, $this->path);
                }

                $ancestor = $candidate;
                $remainder = array_slice($segments, $length);

                break;
            }
        }

        $entry = $map[$ancestor] ?? null;

        if ($entry === null || ! $entry['isArray']) {
            throw ConfigWriteException::pathNotEditable($path, $this->path);
        }

        $depth = $ancestor === '' ? 0 : substr_count($ancestor, '.') + 1;
        $key = array_shift($remainder);

        // Anything still missing between the ancestor and the target becomes a
        // nested literal, so one call can create a whole profile.
        foreach (array_reverse($remainder) as $segment) {
            $value = [$segment => $value];
        }

        $line = str_repeat(self::INDENT, $depth + 1)
            .PhpValue::string((string) $key).' => '.PhpValue::export($value, $depth + 1).',';

        $body = rtrim(substr($source, $entry['bodyStart'], $entry['bodyEnd'] - $entry['bodyStart']));

        if ($body !== '' && ! str_ends_with($body, ',')) {
            $body .= ',';
        }

        $body = ($body === '' ? '' : $body)."\n".$line."\n".str_repeat(self::INDENT, $depth);

        return substr_replace($source, $body, $entry['bodyStart'], $entry['bodyEnd'] - $entry['bodyStart']);
    }

    /**
     * Re-parse the edited source and confirm every touched key is now there.
     *
     * @param  list<string>  $paths
     */
    private function assertParses(string $source, array $paths): void
    {
        try {
            // TOKEN_PARSE makes the tokenizer validate the source, so a broken
            // edit raises here instead of taking the application down on the
            // next request.
            $parsed = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw ConfigWriteException::invalidResult($this->path, $error->getMessage());
        }

        if ($parsed === []) {
            throw ConfigWriteException::invalidResult($this->path, 'the edit emptied the file');
        }

        $map = $this->map($source);

        foreach ($paths as $path) {
            if (! isset($map[$path])) {
                throw ConfigWriteException::invalidResult($this->path, "the key [{$path}] is not readable after the edit");
            }
        }
    }

    /**
     * Index every addressable value in the returned array by its dot path.
     *
     * @return array<string, array{start: int, end: int, isArray: bool, bodyStart: int, bodyEnd: int}>
     */
    private function map(string $source): array
    {
        $tokens = $this->tokenize($source);
        $root = $this->rootArray($tokens);

        if ($root === null) {
            return [];
        }

        $map = [];
        $end = $this->parseArray($tokens, $root, '', $map);
        $closing = $tokens[$end - 1];

        $map[''] = [
            'start' => $tokens[$root]['pos'],
            'end' => $closing['pos'] + 1,
            'isArray' => true,
            'bodyStart' => $tokens[$root]['pos'] + 1,
            'bodyEnd' => $closing['pos'],
        ];

        return $map;
    }

    /**
     * @return list<array{id: int|null, text: string, pos: int}>
     */
    private function tokenize(string $source): array
    {
        $tokens = [];
        $position = 0;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $tokens[] = ['id' => $token[0], 'text' => $token[1], 'pos' => $position];
                $position += strlen($token[1]);

                continue;
            }

            $tokens[] = ['id' => null, 'text' => $token, 'pos' => $position];
            $position += strlen($token);
        }

        return $tokens;
    }

    /**
     * The index of the `[` opening the array the file returns.
     *
     * @param  list<array{id: int|null, text: string, pos: int}>  $tokens
     */
    private function rootArray(array $tokens): ?int
    {
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_RETURN) {
                $next = $this->significant($tokens, $index + 1);

                return $next !== null && $tokens[$next]['text'] === '[' ? $next : null;
            }
        }

        return null;
    }

    /**
     * Walk one array literal, recording every keyed value below $prefix.
     *
     * @param  list<array{id: int|null, text: string, pos: int}>  $tokens
     * @param  array<string, array{start: int, end: int, isArray: bool, bodyStart: int, bodyEnd: int}>  $map
     * @return int The index just past the closing bracket.
     */
    private function parseArray(array $tokens, int $open, string $prefix, array &$map): int
    {
        $index = $open + 1;
        $listIndex = 0;

        while (true) {
            $index = $this->significant($tokens, $index) ?? throw ConfigWriteException::invalidResult($this->path, 'unbalanced array literal');

            if ($tokens[$index]['text'] === ']') {
                return $index + 1;
            }

            $key = null;
            $arrow = $this->significant($tokens, $index + 1);

            if (
                in_array($tokens[$index]['id'], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true)
                && $arrow !== null
                && $tokens[$arrow]['id'] === T_DOUBLE_ARROW
            ) {
                $key = $tokens[$index]['id'] === T_CONSTANT_ENCAPSED_STRING
                    ? $this->unquote($tokens[$index]['text'])
                    : $tokens[$index]['text'];

                $index = $this->significant($tokens, $arrow + 1) ?? throw ConfigWriteException::invalidResult($this->path, 'array entry without a value');
            }

            $path = $prefix === ''
                ? (string) ($key ?? $listIndex)
                : $prefix.'.'.($key ?? $listIndex);

            $start = $tokens[$index]['pos'];

            if ($tokens[$index]['text'] === '[') {
                $after = $this->parseArray($tokens, $index, $path, $map);
                $bodyEnd = $tokens[$after - 1]['pos'];

                $map[$path] = [
                    'start' => $start,
                    'end' => $bodyEnd + 1,
                    'isArray' => true,
                    'bodyStart' => $start + 1,
                    'bodyEnd' => $bodyEnd,
                ];

                $index = $after;
            } else {
                $stop = $this->skipValue($tokens, $index);
                $last = $stop - 1;

                // Trailing whitespace or a comment before the comma is not part
                // of the value: replacing it would eat the comment.
                while ($last > $index && in_array($tokens[$last]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $last--;
                }

                $end = $tokens[$last]['pos'] + strlen($tokens[$last]['text']);

                $map[$path] = [
                    'start' => $start,
                    'end' => $end,
                    'isArray' => false,
                    'bodyStart' => $start,
                    'bodyEnd' => $end,
                ];

                $index = $stop;
            }

            if ($key === null) {
                $listIndex++;
            }

            $index = $this->significant($tokens, $index) ?? throw ConfigWriteException::invalidResult($this->path, 'unbalanced array literal');

            if ($tokens[$index]['text'] === ',') {
                $index++;

                continue;
            }

            if ($tokens[$index]['text'] === ']') {
                return $index + 1;
            }

            throw ConfigWriteException::invalidResult($this->path, 'unexpected token in array literal');
        }
    }

    /**
     * Advance past a non-array value: to the comma or bracket that ends it.
     *
     * @param  list<array{id: int|null, text: string, pos: int}>  $tokens
     */
    private function skipValue(array $tokens, int $index): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            $text = $tokens[$i]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return $i;
                }

                $depth--;

                continue;
            }

            if ($text === ',' && $depth === 0) {
                return $i;
            }
        }

        throw ConfigWriteException::invalidResult($this->path, 'unterminated value');
    }

    /**
     * @param  list<array{id: int|null, text: string, pos: int}>  $tokens
     */
    private function significant(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            if (! in_array($tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    private function unquote(string $literal): string
    {
        $quote = $literal[0];
        $inner = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
            : stripcslashes($inner);
    }
}
