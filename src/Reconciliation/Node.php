<?php

namespace Platform\Forecast\Reconciliation;

/**
 * Ein Knoten im Reconciliation-Baum — baum-agnostisch.
 *
 * Derselbe Knoten bildet BEIDE Achsen ab:
 *   - Zeit:         Jahr → Monat → Tag → Stunde
 *   - Organisation: Region → Standort → ...
 *
 * Die eine Invariante ("es muss immer aufgehen"):
 *
 *   value = max(eigene Schätzung, Σ Detail-Kinder) + Σ Plus-Kinder
 *   rest  = max(0, eigene Schätzung − Σ Detail-Kinder)
 *
 * - "eigene Schätzung" (estimate) ist der Wert, der direkt AN diesem Knoten
 *   eingetragen wurde (top-down), z.B. Monat = 50.000. Kann null sein.
 * - Detail-Kinder verfeinern die Schätzung von unten (carve-in), ohne die
 *   Summe zu erhöhen. Übersteigen sie die Schätzung, wächst der Wert mit
 *   (max) — so geht es IMMER auf.
 * - Plus-Kinder sind echte Zusätze und erhöhen den Wert.
 * - rest ist der noch nicht konkretisierte Teil der Schätzung.
 */
final class Node
{
    /** @var list<array{0: Node, 1: Mode}> */
    private array $children = [];

    public function __construct(
        public readonly string $key = '',
        public ?float $estimate = null,
    ) {}

    public function addChild(Node $child, Mode $mode): Node
    {
        $this->children[] = [$child, $mode];
        return $this;
    }

    /** @return list<array{0: Node, 1: Mode}> */
    public function children(): array
    {
        return $this->children;
    }

    public function detailSum(): float
    {
        $sum = 0.0;
        foreach ($this->children as [$child, $mode]) {
            if ($mode === Mode::Detail) {
                $sum += $child->value();
            }
        }
        return $sum;
    }

    public function plusSum(): float
    {
        $sum = 0.0;
        foreach ($this->children as [$child, $mode]) {
            if ($mode === Mode::Plus) {
                $sum += $child->value();
            }
        }
        return $sum;
    }

    /** Der aggregierte Wert dieses Knotens (rollt zu seinem Elternknoten hoch). */
    public function value(): float
    {
        return max($this->estimate ?? 0.0, $this->detailSum()) + $this->plusSum();
    }

    /** Der noch offene (geschätzte, nicht konkretisierte) Teil. */
    public function rest(): float
    {
        return max(0.0, ($this->estimate ?? 0.0) - $this->detailSum());
    }
}
