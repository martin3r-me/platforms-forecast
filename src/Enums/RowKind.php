<?php

namespace Platform\Forecast\Enums;

/**
 * Art einer Planungs-Zeile.
 *
 * - Input:     freie Eingabe (Euro-Zahl je Zeit-Bucket)
 * - Sum:       Summe anderer Zeilen (config: ['rows' => [rowKey, ...]])
 * - Percent:   Prozentwert (config: ['of' => rowKey, 'base' => rowKey])
 * - Reference: Verweis auf eine Zeile einer anderen Planung
 *              (config: ['plan_uuid' => ..., 'row_key' => ...]) — später
 */
enum RowKind: string
{
    case Input = 'input';
    case Sum = 'sum';
    case Percent = 'percent';
    case Reference = 'reference';
}
