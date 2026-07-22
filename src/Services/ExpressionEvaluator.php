<?php

namespace Platform\Forecast\Services;

/**
 * Sichere Ausdruck-Auswertung für Formelzeilen (config.expr) — KEIN eval().
 *
 * Eigener Tokenizer + Recursive-Descent-Parser + Evaluator. Nur die whitelisteten
 * Operatoren/Funktionen sind erlaubt (Nutzer-/Agenten-Eingabe = untrusted).
 *
 * Referenzen auf andere Zeilen: [row.key] → Wert dieser Zeile im aktuellen Bucket.
 * Präzedenz (aufsteigend): ?:  ||  &&  == !=  < > <= >=  + -  * / %  unär(- !)  primär.
 * Funktionen: IF(c,a,b) · MIN/MAX/SUM(...) · ABS · ROUND(x[,n]) · FLOOR · CEIL · CLAMP(x,lo,hi).
 * Wahrheitswerte: Vergleiche liefern 1/0; nicht-0 gilt als wahr. true/false erlaubt.
 */
final class ExpressionEvaluator
{
    /** @var list<string> multi-char zuerst, damit >= nicht als > gelesen wird */
    private const OPS = ['>=', '<=', '==', '!=', '&&', '||', '+', '-', '*', '/', '%', '>', '<', '!', '(', ')', ',', '?', ':'];

    private array $tokens = [];

    private int $pos = 0;

    /**
     * Ausdruck zu einem AST kompilieren (einmal parsen, viele Buckets auswerten).
     *
     * @return array{ast: array, refs: list<string>}
     */
    public static function compile(string $expr): array
    {
        $self = new self();
        $self->tokens = self::tokenize($expr);
        if ($self->tokens === []) {
            throw new \InvalidArgumentException('Leerer Ausdruck.');
        }
        $ast = $self->parseExpr();
        if ($self->pos < count($self->tokens)) {
            throw new \InvalidArgumentException('Unerwartetes Zeichen am Ende des Ausdrucks.');
        }
        $refs = [];
        foreach ($self->tokens as $t) {
            if ($t['type'] === 'ref') {
                $refs[$t['value']] = true;
            }
        }

        return ['ast' => $ast, 'refs' => array_keys($refs)];
    }

    /** @param array{ast: array, refs: list<string>} $compiled */
    public static function evaluate(array $compiled, callable $resolve): float
    {
        return self::ev($compiled['ast'], $resolve);
    }

    // ───────────────────────── Tokenizer ─────────────────────────

    /** @return list<array{type:string, value:string}> */
    private static function tokenize(string $s): array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $ch = $s[$i];
            if (ctype_space($ch)) {
                $i++;

                continue;
            }
            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
                $j = $i;
                while ($j < $n && (ctype_digit($s[$j]) || $s[$j] === '.')) {
                    $j++;
                }
                $tokens[] = ['type' => 'num', 'value' => substr($s, $i, $j - $i)];
                $i = $j;

                continue;
            }
            if ($ch === '[') {
                $j = strpos($s, ']', $i);
                if ($j === false) {
                    throw new \InvalidArgumentException('Nicht geschlossene [ Referenz im Ausdruck.');
                }
                $tokens[] = ['type' => 'ref', 'value' => trim(substr($s, $i + 1, $j - $i - 1))];
                $i = $j + 1;

                continue;
            }
            if (ctype_alpha($ch) || $ch === '_') {
                $j = $i;
                while ($j < $n && (ctype_alnum($s[$j]) || $s[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['type' => 'ident', 'value' => substr($s, $i, $j - $i)];
                $i = $j;

                continue;
            }
            $matched = null;
            foreach (self::OPS as $op) {
                if (substr($s, $i, strlen($op)) === $op) {
                    $matched = $op;
                    break;
                }
            }
            if ($matched === null) {
                throw new \InvalidArgumentException("Unerwartetes Zeichen '{$ch}' im Ausdruck.");
            }
            $tokens[] = ['type' => 'op', 'value' => $matched];
            $i += strlen($matched);
        }

        return $tokens;
    }

    // ───────────────────────── Parser (Recursive Descent) ─────────────────────────

    private function parseExpr(): array
    {
        $cond = $this->parseOr();
        if ($this->isOp('?')) {
            $this->advance();
            $a = $this->parseExpr();
            $this->expectOp(':');
            $b = $this->parseExpr();

            return ['tern', $cond, $a, $b];
        }

        return $cond;
    }

    private function parseOr(): array
    {
        $l = $this->parseAnd();
        while ($this->isOp('||')) {
            $this->advance();
            $l = ['bin', '||', $l, $this->parseAnd()];
        }

        return $l;
    }

    private function parseAnd(): array
    {
        $l = $this->parseEquality();
        while ($this->isOp('&&')) {
            $this->advance();
            $l = ['bin', '&&', $l, $this->parseEquality()];
        }

        return $l;
    }

    private function parseEquality(): array
    {
        $l = $this->parseComparison();
        while ($this->isOp('==') || $this->isOp('!=')) {
            $op = $this->cur()['value'];
            $this->advance();
            $l = ['bin', $op, $l, $this->parseComparison()];
        }

        return $l;
    }

    private function parseComparison(): array
    {
        $l = $this->parseAdd();
        while ($this->isOp('>') || $this->isOp('<') || $this->isOp('>=') || $this->isOp('<=')) {
            $op = $this->cur()['value'];
            $this->advance();
            $l = ['bin', $op, $l, $this->parseAdd()];
        }

        return $l;
    }

    private function parseAdd(): array
    {
        $l = $this->parseMul();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->cur()['value'];
            $this->advance();
            $l = ['bin', $op, $l, $this->parseMul()];
        }

        return $l;
    }

    private function parseMul(): array
    {
        $l = $this->parseUnary();
        while ($this->isOp('*') || $this->isOp('/') || $this->isOp('%')) {
            $op = $this->cur()['value'];
            $this->advance();
            $l = ['bin', $op, $l, $this->parseUnary()];
        }

        return $l;
    }

    private function parseUnary(): array
    {
        if ($this->isOp('-')) {
            $this->advance();

            return ['un', '-', $this->parseUnary()];
        }
        if ($this->isOp('!')) {
            $this->advance();

            return ['un', '!', $this->parseUnary()];
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $t = $this->cur();
        if ($t === null) {
            throw new \InvalidArgumentException('Unerwartetes Ende des Ausdrucks.');
        }
        if ($t['type'] === 'num') {
            $this->advance();

            return ['num', (float) $t['value']];
        }
        if ($t['type'] === 'ref') {
            $this->advance();

            return ['ref', $t['value']];
        }
        if ($t['type'] === 'op' && $t['value'] === '(') {
            $this->advance();
            $e = $this->parseExpr();
            $this->expectOp(')');

            return $e;
        }
        if ($t['type'] === 'ident') {
            $name = strtolower($t['value']);
            $this->advance();
            if ($name === 'true') {
                return ['num', 1.0];
            }
            if ($name === 'false') {
                return ['num', 0.0];
            }
            $this->expectOp('(');
            $args = [];
            if (! $this->isOp(')')) {
                $args[] = $this->parseExpr();
                while ($this->isOp(',')) {
                    $this->advance();
                    $args[] = $this->parseExpr();
                }
            }
            $this->expectOp(')');

            return ['call', $name, $args];
        }

        throw new \InvalidArgumentException("Unerwartetes Token '{$t['value']}' im Ausdruck.");
    }

    private function cur(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function isOp(string $v): bool
    {
        $t = $this->cur();

        return $t !== null && $t['type'] === 'op' && $t['value'] === $v;
    }

    private function expectOp(string $v): void
    {
        if (! $this->isOp($v)) {
            throw new \InvalidArgumentException("Erwartet: '{$v}'.");
        }
        $this->advance();
    }

    // ───────────────────────── Evaluator ─────────────────────────

    private static function ev(array $node, callable $resolve): float
    {
        switch ($node[0]) {
            case 'num':
                return (float) $node[1];
            case 'ref':
                return (float) $resolve($node[1]);
            case 'un':
                $v = self::ev($node[2], $resolve);

                return $node[1] === '-' ? -$v : ($v == 0.0 ? 1.0 : 0.0);
            case 'tern':
                return self::ev($node[1], $resolve) != 0.0 ? self::ev($node[2], $resolve) : self::ev($node[3], $resolve);
            case 'bin':
                $op = $node[1];
                if ($op === '&&') {
                    return (self::ev($node[2], $resolve) != 0.0 && self::ev($node[3], $resolve) != 0.0) ? 1.0 : 0.0;
                }
                if ($op === '||') {
                    return (self::ev($node[2], $resolve) != 0.0 || self::ev($node[3], $resolve) != 0.0) ? 1.0 : 0.0;
                }
                $a = self::ev($node[2], $resolve);
                $b = self::ev($node[3], $resolve);

                return match ($op) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    '/' => $b == 0.0 ? 0.0 : $a / $b,
                    '%' => $b == 0.0 ? 0.0 : fmod($a, $b),
                    '>' => $a > $b ? 1.0 : 0.0,
                    '<' => $a < $b ? 1.0 : 0.0,
                    '>=' => $a >= $b ? 1.0 : 0.0,
                    '<=' => $a <= $b ? 1.0 : 0.0,
                    '==' => $a == $b ? 1.0 : 0.0,
                    '!=' => $a != $b ? 1.0 : 0.0,
                    default => 0.0,
                };
            case 'call':
                $args = array_map(fn ($x) => self::ev($x, $resolve), $node[2]);

                return self::callFn($node[1], $args);
        }

        return 0.0;
    }

    /** @param list<float> $a */
    private static function callFn(string $name, array $a): float
    {
        return match ($name) {
            'if' => count($a) >= 3 ? ($a[0] != 0.0 ? $a[1] : $a[2]) : 0.0,
            'min' => $a ? min($a) : 0.0,
            'max' => $a ? max($a) : 0.0,
            'sum' => array_sum($a),
            'abs' => abs($a[0] ?? 0.0),
            'round' => round($a[0] ?? 0.0, (int) ($a[1] ?? 0)),
            'floor' => floor($a[0] ?? 0.0),
            'ceil' => ceil($a[0] ?? 0.0),
            'clamp' => max($a[1] ?? 0.0, min($a[0] ?? 0.0, $a[2] ?? 0.0)),
            default => throw new \InvalidArgumentException("Unbekannte Funktion '{$name}'."),
        };
    }
}
