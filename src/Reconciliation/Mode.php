<?php

namespace Platform\Forecast\Reconciliation;

/**
 * Modus einer Eingabe relativ zu ihrem Eltern-Knoten.
 *
 * - Detail: Der Wert ist ein *Teil* der Schätzung, die schon oben steht.
 *           Er verändert die Summe NICHT, sondern knabbert nur vom Rest ab.
 * - Plus:   Der Wert kommt *zusätzlich* obendrauf und erhöht die Summe.
 */
enum Mode: string
{
    case Detail = 'detail';
    case Plus = 'plus';
}
