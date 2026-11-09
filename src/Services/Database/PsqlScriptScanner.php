<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Database;

use RuntimeException;

/**
 * Best-effort check of a restore script for psql client directives.
 *
 * Tracks quoted strings, escape strings, dollar quotes, quoted identifiers, line
 * comments, nested block comments and COPY data over raw bytes, in fixed-size
 * chunks, so a dump of any size is inspected in constant memory.
 *
 * It is written against what pg_dump produces and refuses constructs it does not
 * recognise. It is one layer of defense and does not prove a script is harmless.
 *
 * @internal
 */
final class PsqlScriptScanner
{
    private const int CHUNK_BYTES = 1_048_576;

    /**
     * Upper bound for any single token, restrict line or session setting.
     */
    private const int LOOKAHEAD_BYTES = 8_192;

    /**
     * Room for a COPY header naming every column of the widest possible table.
     */
    private const int COPY_HEADER_BYTES = 524_288;

    /**
     * The script is bytes, not text: every length and offset here is in bytes.
     */
    private const string BYTES = '8bit';

    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private const string UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const string LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

    private const string NAME = '(?:[A-Za-z_\x80-\xFF][A-Za-z0-9_$\x80-\xFF]*+|"(?:[^"]++|"")++")';

    private const string GAP = '[ \t\n\f]*+';

    /**
     * Exactly the COPY statement pg_dump writes, up to and including the end of its line.
     */
    private const string COPY_HEADER = '/COPY[ \t\n\f]++'.self::NAME.'(?:'.self::GAP.'\.'.self::GAP.self::NAME.')*+'.self::GAP
        .'(?:\('.self::GAP.self::NAME.'(?:'.self::GAP.','.self::GAP.self::NAME.')*+'.self::GAP.'\)'.self::GAP.')?+'
        .'FROM[ \t\n\f]++STDIN'.self::GAP.';[ \t\f]*+\r?\n/Ai';

    private const string WORD = '/[A-Za-z_\x80-\xFF][A-Za-z0-9_$\x80-\xFF]*+/A';

    private const string NUMBER = '/(?:[0-9]++(?:\.(?!\.)[0-9]*+)?+|\.[0-9]++)(?:[eE][+-]?[0-9]++)?+/A';

    private const string PARAMETER = '/\$[0-9]++/A';

    private const string DOLLAR_DELIMITER = '/\$(?:[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*+)?+\$/A';

    private const string COLON_TOKEN = '/:(?::|=|[A-Za-z0-9_\x80-\xFF]++|\'[A-Za-z0-9_\x80-\xFF]++\'|"[A-Za-z0-9_\x80-\xFF]++"|\{\?[A-Za-z0-9_\x80-\xFF]++\})?+/A';

    /**
     * The whole line must be the directive and an alphanumeric key.
     */
    private const string RESTRICT_LINE = '/^\s*\\\\(?:un)?restrict [0-9A-Za-z]{1,128}\s*$/D';

    private const string WATCHED_SETTING = '/standard_conforming_strings|client_encoding|\bnames\b|\ball\b/i';

    /**
     * The forms pg_dump writes for the watched settings. A tablespace or access
     * method may itself be called `names` or `all`.
     */
    private const string ACCEPTED_SETTING = '/\ASET\s+(?:standard_conforming_strings\s*=\s*on'
        .'|client_encoding\s*=\s*\'(?:UTF8|SQL_ASCII|LATIN(?:[1-9]|10)|WIN(?:866|874|125[0-8])|KOI8[RU]|ISO_8859_[5-8]|EUC_(?:JP|CN|KR|TW|JIS_2004)|MULE_INTERNAL)\''
        .'|default_table(?:space|_access_method)\s*=[^;]*+)\s*\z/i';

    private const array ROUTINE_HEADER_WORDS = ['create' => 'c', 'function' => 'f', 'procedure' => 'p', 'or' => 'o', 'replace' => 'r'];

    /**
     * A plain pg_dump writes one restrict line and one unrestrict line. Refusing
     * more keeps the recorded positions, and so the scan, in constant memory.
     */
    private const int MAX_RESTRICT_LINES = 2;

    private string $buffer = '';

    private int $pos = 0;

    /**
     * Bytes of the script already dropped from the front of the window.
     */
    private int $windowStart = 0;

    /**
     * @var list<array{0: int, 1: int}>
     */
    private array $restrictLines = [];

    private int $length = 0;

    private int $line = 1;

    private bool $exhausted = false;

    private bool $atLineStart = true;

    private bool $atStatementStart = true;

    private int $parenDepth = 0;

    private int $beginDepth = 0;

    private int $wordCount = 0;

    /**
     * @var array{0: string, 1: string, 2: string, 3: string}
     */
    private array $headerWords = ['', '', '', ''];

    /**
     * @param  resource  $handle
     */
    public function __construct(private $handle) {}

    /**
     * @return list<array{0: int, 1: int}> Byte offset and length of each restrict
     *                                     or unrestrict line, in file order, from
     *                                     its backslash up to its line feed.
     *
     * @throws RuntimeException When the script is refused or cannot be read.
     */
    public function scan(): array
    {
        $this->fill();

        if (str_starts_with($this->buffer, self::BYTE_ORDER_MARK)) {
            $this->cannotVerify();
        }

        while (($byte = $this->peek()) !== null) {
            match ($byte) {
                "\n" => $this->newLine(),
                ' ', "\t", "\f" => $this->pos++,
                "\r" => $this->carriageReturn(),
                '-' => $this->dash(),
                '/' => $this->slash(),
                '\\' => $this->backslash(),
                default => $this->token($byte),
            };
        }

        if (! $this->statementIsEmpty()) {
            $this->cannotVerify();
        }

        return $this->restrictLines;
    }

    private function newLine(): void
    {
        $this->pos++;
        $this->line++;
        $this->atLineStart = true;
    }

    private function carriageReturn(): void
    {
        $this->peek();

        if (($this->buffer[$this->pos + 1] ?? '') !== "\n") {
            $this->cannotVerify();
        }

        $this->pos++;
    }

    private function dash(): void
    {
        if (($this->buffer[$this->pos + 1] ?? '') === '-') {
            $this->lineComment();

            return;
        }

        $this->token('-');
    }

    private function lineComment(): void
    {
        $this->atLineStart = false;
        $this->pos += 2;

        do {
            $this->pos += strcspn($this->buffer, "\r\n", $this->pos);
        } while (! isset($this->buffer[$this->pos]) && $this->fill());

        if ($this->peek() === "\r") {
            $this->carriageReturn();
        }
    }

    private function slash(): void
    {
        if (($this->buffer[$this->pos + 1] ?? '') === '*') {
            $this->blockComment();

            return;
        }

        $this->token('/');
    }

    private function blockComment(): void
    {
        $this->atLineStart = false;
        $this->pos += 2;
        $depth = 1;

        while ($depth > 0) {
            $this->skipTo('/*');
            $this->peek();

            $pair = mb_substr($this->buffer, $this->pos, 2, self::BYTES);

            if ($pair === '/*' || $pair === '*/') {
                $depth += $pair === '/*' ? 1 : -1;
                $this->pos += 2;

                continue;
            }

            $this->pos++;
        }
    }

    /**
     * Outside every quoted state a backslash is always a client directive; the
     * only exception is a whole restrict or unrestrict line between statements.
     */
    private function backslash(): void
    {
        $span = strcspn($this->buffer, "\n", $this->pos);

        $isRestrictLine = $this->atLineStart
            && $this->statementIsEmpty()
            && $span < self::LOOKAHEAD_BYTES
            && preg_match(self::RESTRICT_LINE, mb_substr($this->buffer, $this->pos, $span, self::BYTES)) === 1;

        if (! $isRestrictLine) {
            SqlDirectiveGuard::reject('psql', $this->line);
        }

        if (count($this->restrictLines) === self::MAX_RESTRICT_LINES) {
            $this->cannotVerify();
        }

        $this->restrictLines[] = [$this->windowStart + $this->pos, $span];
        $this->pos += $span;
    }

    private function token(string $byte): void
    {
        $startsStatement = $this->atStatementStart;

        $this->atStatementStart = false;
        $this->atLineStart = false;

        match ($byte) {
            "'" => $this->plainString(),
            '"' => $this->quotedIdentifier(),
            '$' => $this->dollar(),
            ':' => $this->colon(),
            ';' => $this->semicolon(),
            '(' => $this->openParenthesis(),
            ')' => $this->closeParenthesis(),
            default => $this->wordOrSymbol($byte, $startsStatement),
        };
    }

    private function plainString(): void
    {
        $this->pos++;

        while (true) {
            $this->skipTo("'");
            $this->pos++;

            if ($this->peek() !== "'") {
                return;
            }

            $this->pos++;
        }
    }

    private function escapeString(): void
    {
        $this->pos++;

        while (true) {
            $this->skipTo("\\'");
            $this->peek();

            if ($this->buffer[$this->pos] === '\\') {
                $this->consume(min(2, $this->length - $this->pos));

                continue;
            }

            $this->pos++;

            if ($this->peek() !== "'") {
                return;
            }

            $this->pos++;
        }
    }

    private function quotedIdentifier(): void
    {
        $this->pos++;

        while (true) {
            $this->skipTo('"');
            $this->pos++;

            if ($this->peek() !== '"') {
                return;
            }

            $this->pos++;
        }
    }

    private function dollar(): void
    {
        if (ctype_digit($this->buffer[$this->pos + 1] ?? '')) {
            $this->pos += mb_strlen($this->wholeToken(self::PARAMETER), self::BYTES);
            $this->refuseAttachedWord();

            return;
        }

        $delimiter = $this->wholeToken(self::DOLLAR_DELIMITER);
        $size = mb_strlen($delimiter, self::BYTES);

        $this->pos += $size;

        while (true) {
            $this->skipTo('$');
            $this->require($size);

            if (substr_compare($this->buffer, $delimiter, $this->pos, $size) === 0) {
                $this->pos += $size;

                return;
            }

            $this->pos++;
        }
    }

    private function colon(): void
    {
        $this->pos += mb_strlen($this->wholeToken(self::COLON_TOKEN), self::BYTES);
    }

    private function semicolon(): void
    {
        $this->pos++;
        $this->atStatementStart = true;

        if ($this->parenDepth === 0 && $this->beginDepth === 0) {
            $this->wordCount = 0;
        }
    }

    private function openParenthesis(): void
    {
        $this->pos++;
        $this->parenDepth++;
    }

    private function closeParenthesis(): void
    {
        $this->pos++;

        if ($this->parenDepth > 0) {
            $this->parenDepth--;
        }
    }

    private function wordOrSymbol(string $byte, bool $startsStatement): void
    {
        $code = ord($byte);

        if (ctype_digit($byte) || ($byte === '.' && ctype_digit($this->buffer[$this->pos + 1] ?? ''))) {
            $this->pos += mb_strlen($this->wholeToken(self::NUMBER), self::BYTES);
            $this->refuseAttachedWord();

            return;
        }

        if (ctype_alpha($byte) || $byte === '_' || $code >= 0x80) {
            $this->word($startsStatement);

            return;
        }

        if ($code < 0x20 || $code === 0x7F) {
            $this->cannotVerify();
        }

        $this->pos++;
    }

    private function word(bool $startsStatement): void
    {
        $word = $this->wholeToken(self::WORD);
        $size = mb_strlen($word, self::BYTES);
        $lowercase = strtr($word, self::UPPERCASE, self::LOWERCASE);

        if ($size === 1 && $this->literalPrefix($lowercase, $this->buffer[$this->pos + 1] ?? '')) {
            return;
        }

        if ($startsStatement && $lowercase === 'copy') {
            $this->copyHeader();

            return;
        }

        if ($startsStatement && ($lowercase === 'set' || $lowercase === 'reset')) {
            $this->sessionSetting();
        }

        $this->pos += $size;
        $this->trackRoutineBody($lowercase);
    }

    private function literalPrefix(string $letter, string $next): bool
    {
        if ($next === "'" && $letter === 'e') {
            $this->pos++;
            $this->escapeString();

            return true;
        }

        if ($next === "'" && in_array($letter, ['b', 'x', 'n'], true)) {
            $this->pos++;
            $this->plainString();

            return true;
        }

        if ($next === '&' && $letter === 'u') {
            $this->pos++;

            return true;
        }

        return false;
    }

    private function copyHeader(): void
    {
        $this->require(self::COPY_HEADER_BYTES);

        if ($this->parenDepth > 0 || $this->beginDepth > 0 || preg_match(self::COPY_HEADER, $this->buffer, $matches, 0, $this->pos) !== 1) {
            $this->cannotVerify();
        }

        $this->consume(mb_strlen($matches[0], self::BYTES));
        $this->wordCount = 0;
        $this->copyData();
        $this->atStatementStart = true;
        $this->atLineStart = true;
    }

    private function copyData(): void
    {
        while (true) {
            if ($this->peek() === null) {
                $this->cannotVerify();
            }

            if ($this->isCopyEndMarker()) {
                $this->pos += $this->buffer[$this->pos + 2] === "\r" ? 4 : 3;
                $this->line++;

                return;
            }

            $this->skipLine();
        }
    }

    /**
     * The end-of-data line, with either line ending.
     */
    private function isCopyEndMarker(): bool
    {
        return $this->buffer[$this->pos] === '\\'
            && (substr_compare($this->buffer, "\\.\n", $this->pos, 3) === 0
                || substr_compare($this->buffer, "\\.\r\n", $this->pos, 4) === 0);
    }

    private function skipLine(): void
    {
        do {
            $this->pos += strcspn($this->buffer, "\n", $this->pos);
        } while (! isset($this->buffer[$this->pos]) && $this->fill());

        if (isset($this->buffer[$this->pos])) {
            $this->pos++;
            $this->line++;
        }
    }

    /**
     * Watched settings are accepted only in the forms pg_dump writes.
     */
    private function sessionSetting(): void
    {
        $span = strcspn($this->buffer, ';', $this->pos);

        if ($span >= self::LOOKAHEAD_BYTES) {
            $this->cannotVerify();
        }

        $setting = mb_substr($this->buffer, $this->pos, $span, self::BYTES);

        if (preg_match(self::WATCHED_SETTING, $setting) === 1 && preg_match(self::ACCEPTED_SETTING, $setting) !== 1) {
            $this->cannotVerify();
        }
    }

    /**
     * Tracks routine bodies, whose semicolons do not end the statement.
     */
    private function trackRoutineBody(string $word): void
    {
        if ($this->wordCount === 0) {
            $this->headerWords = ['', '', '', ''];
        }

        if ($this->wordCount < 4 && isset(self::ROUTINE_HEADER_WORDS[$word])) {
            $this->headerWords[$this->wordCount] = self::ROUTINE_HEADER_WORDS[$word];
        }

        $this->wordCount++;

        if ($this->parenDepth > 0 || ! $this->isRoutineHeader()) {
            return;
        }

        if ($word === 'begin' || ($word === 'case' && $this->beginDepth >= 1)) {
            $this->beginDepth++;
        } elseif ($word === 'end' && $this->beginDepth > 0) {
            $this->beginDepth--;
        }
    }

    private function isRoutineHeader(): bool
    {
        [$first, $second, $third, $fourth] = $this->headerWords;

        return $first === 'c' && (
            $second === 'f' || $second === 'p'
            || ($second === 'o' && $third === 'r' && ($fourth === 'f' || $fourth === 'p'))
        );
    }

    private function statementIsEmpty(): bool
    {
        return $this->atStatementStart && $this->parenDepth === 0 && $this->beginDepth === 0;
    }

    /**
     * A number or parameter must not run straight into a word.
     */
    private function refuseAttachedWord(): void
    {
        $next = $this->buffer[$this->pos] ?? '';

        if ($next !== '' && (ctype_alpha($next) || $next === '_' || $next === '$' || ord($next) >= 0x80)) {
            $this->cannotVerify();
        }
    }

    /**
     * Matches a token that must end inside the loaded window.
     */
    private function wholeToken(string $pattern): string
    {
        if (preg_match($pattern, $this->buffer, $matches, 0, $this->pos) !== 1) {
            $this->cannotVerify();
        }

        if ($this->pos + mb_strlen($matches[0], self::BYTES) >= $this->length && ! $this->exhausted) {
            $this->cannotVerify();
        }

        return $matches[0];
    }

    /**
     * Stops on the first of the given bytes; running out of input means a quoted
     * state never closed.
     */
    private function skipTo(string $bytes): void
    {
        while (true) {
            $this->consume(strcspn($this->buffer, $bytes, $this->pos));

            if (isset($this->buffer[$this->pos])) {
                return;
            }

            if (! $this->fill()) {
                $this->cannotVerify();
            }
        }
    }

    private function consume(int $bytes): void
    {
        $this->line += $this->countNewlines($this->buffer, $this->pos, $bytes);
        $this->pos += $bytes;
    }

    private function countNewlines(string $bytes, int $offset, int $length): int
    {
        $count = 0;
        $end = $offset + $length;

        while (($offset += strcspn($bytes, "\n", $offset, $end - $offset)) < $end) {
            $offset++;
            $count++;
        }

        return $count;
    }

    private function peek(): ?string
    {
        $this->require(self::LOOKAHEAD_BYTES);

        return $this->buffer[$this->pos] ?? null;
    }

    private function require(int $bytes): void
    {
        while ($this->length - $this->pos < $bytes && $this->fill()) {
            // Keep reading until the window holds enough bytes or the script ends.
        }
    }

    private function fill(): bool
    {
        if ($this->exhausted) {
            return false;
        }

        $chunk = fread($this->handle, self::CHUNK_BYTES);

        if ($chunk === false) {
            throw new RuntimeException('Unable to read SQL dump for inspection.');
        }

        if ($chunk === '') {
            $this->exhausted = true;

            return false;
        }

        if (str_contains($chunk, "\0")) {
            $this->line += $this->countNewlines($this->buffer, $this->pos, $this->length - $this->pos)
                + $this->countNewlines($chunk, 0, strcspn($chunk, "\0"));

            $this->cannotVerify();
        }

        $this->buffer = mb_substr($this->buffer, $this->pos, null, self::BYTES).$chunk;
        $this->windowStart += $this->pos;
        $this->pos = 0;
        $this->length = mb_strlen($this->buffer, self::BYTES);

        return true;
    }

    private function cannotVerify(): never
    {
        SqlDirectiveGuard::rejectUnverifiable('psql', $this->line);
    }
}
