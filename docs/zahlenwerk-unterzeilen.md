# Zahlenwerk — Unterzeilen / Aufschlüsselung einer Zelle

**Status:** Umgesetzt (Code, noch nicht deployed) · **Stand:** 2026-10-26

> **Umsetzungs-Hinweis (gewählter Ansatz):** Der Zellwert in `forecast_entries` wird beim
> Schreiben synchron als **Σ der Unterzeilen** gepflegt (`PlanService::setBreakdown`). Dadurch
> bleiben **Reconciliation, Zeitverteilung (Jahr→Monat), Rollup und Export unverändert** — sie
> sehen weiter nur den fertigen Zellwert. Das ist bewusst risikoarm gewählt statt des
> alternativen „estimate=null + Detail-Kinder im Node"-Wegs; das Ergebnis (Zelle = Σ) ist
> identisch. Siehe „Umgesetzte Teile" unten.
**Herkunft:** Rollout-Meeting Broich Catering (26.10.2026) — Wunsch der Planer (Olli, Christian), Rahmen von Martin. Von Hendric in Richtung Rolling Forecast eingeordnet.

---

## 1. Problem / Wunsch

Ein Planer sieht eine Kostensumme (z. B. „Telefon & Porto = 105.000 €") und will **nachvollziehbar machen, woraus sie sich zusammensetzt** — „das kommt von Bange Profi, das von X, dazu ein Reifenwechsel". Heute passiert das in Excel-Nebenblättern und geht beim Umstieg aufs Zahlenwerk verloren.

Reine **Notizen reichen nicht** (nicht summierbar, schlecht verwertbar). Es braucht **echte, benannte Teilbeträge**, deren Summe die Zelle ergibt.

## 2. Modell (entschieden)

Die Funktion liegt **pro Zelle, optional** auf Eingabezeilen. Für eine Zelle gilt **entweder/oder**:

- **Direktwert:** eine Zahl eintippen (wie heute), **oder**
- **Aufschlüsselung:** benannte Unterzeilen (Label + Betrag) anlegen. Dann ist die **Zelle = Σ der Unterzeilen** und **nicht mehr frei tippbar** (read-only, zeigt die Summe).

**Zeit-Dimension:** Die Unterzeilen hängen an der Zelle, die gerade bearbeitet wird, und erben deren Zeit-Ebene.
- Aufschlüsselung auf **Monatszelle** → Unterposten dieses Monats.
- Aufschlüsselung auf **Jahreszelle** → Unterposten des Jahres; die Summe verteilt sich nach unten auf die Monate **mit genau der Verteil-Logik eines normal getippten Jahreswerts**.

→ Es gibt kein „12× aufschlüsseln": man schlüsselt auf der Ebene auf, auf der man ohnehin plant.

**DATEV/Export:** Nur die **Oberkategorie** verlässt das Modul. Unterzeilen bleiben intern (Nachvollziehbarkeit).

### Invariante
Solange Unterzeilen existieren: Oberzelle = exakte Summe, nicht editierbar. Keine „kaputt gebogene Formel" wie in Excel möglich. Letzte Unterzeile gelöscht → Zelle wird wieder frei tippbar.

## 3. Engine — was schon trägt

Die Rechenlogik existiert bereits in `src/Reconciliation/`:

- `Mode::Detail` (`Mode.php`): ein Eintrag ist ein **Teil** der Zelle.
- `Node::detailSum()` summiert die Detail-Kinder (vorzeichenerhaltend).
- `Node::baseValue()` (`Node.php`) deckt den Zielfall direkt ab:
  - **kein eigener `estimate` → `detailSum()`** = „Zelle ist genau die Summe ihrer Unterzeilen". **Das ist der Aufschlüsselungs-Modus.**
  - `estimate` ohne Detail-Kinder → Direktwert (heutiger Normalfall).
  - `estimate` **mit** Detail-Kindern → `max(estimate, detailSum)` = Top-down-Carve-in mit verteilbarem `rest()` (anderer Anwendungsfall, für diese Funktion **nicht** gewünscht — hier bleibt `estimate = null`).

→ Für die reine Aufschlüsselung muss die Engine **nicht** umgebaut werden; die Zelle läuft über den `estimate === null`-Pfad.

## 4. Was fehlt

### 4.1 Speicher
`forecast_entries` hat heute `unique(plan_id, row_key, bucket_key)` → **genau ein Wert pro Zelle**, und **keine Label-Spalte**. Mehrere benannte Unterposten pro Zelle sind so nicht ablegbar.

**Empfehlung:** eigene Tabelle statt das Unique-Constraint zu lockern —

```
forecast_entry_details
  id
  team_id            FK teams
  plan_id            FK forecast_plans (cascade)
  row_key            string
  bucket_key         string   // dieselbe Zeit-Ebene wie die Oberzelle
  level              string   // year | month | day | hour
  label              string   // „Bange Profi", „Reifenwechsel", …
  value              decimal(20,4)  // vorzeichenerhaltend (Entlastung = negativ)
  ordinal            unsigned // Reihenfolge im Modal
  timestamps
  index (plan_id, row_key, bucket_key)
```

Die Oberzelle in `forecast_entries` wird bei aktiver Aufschlüsselung **nicht** mit einem eigenen Wert geführt (bzw. markiert als „aus Detail abgeleitet"), damit `estimate === null` greift und `value = Σ details` gilt.

### 4.2 Reconciliation-Anbindung
Beim Aufbau des `Node`-Baums für eine aufgeschlüsselte Zelle: pro `forecast_entry_detail` ein Kind mit `Mode::Detail` an die Zellen-Node hängen, Zellen-`estimate` auf `null`. Summe und Verteilung ergeben sich dann aus der bestehenden Logik.

### 4.3 UI (Modal)
- Auslöser pro Eingabezelle: kleines Icon/Kontextmenü „Aufschlüsseln".
- Modal: Liste aus `[Label] [Betrag]`-Zeilen, add/remove, Summe live unten, Reihenfolge per `ordinal`.
- Nach Speichern: Zelle zeigt die Summe + dezenten Marker „aufgeschlüsselt" (z. B. ƒ-/Detail-Badge), Klick öffnet das Modal erneut. Oberzelle read-only.
- Anzeige respektiert die bestehende Vorzeichen-/Farb-Konvention (negativer Unterposten in einer Kostenzeile = Entlastung = grün).

### 4.4 Export
DATEV-/Rollup-Export zieht weiterhin nur die Zeilen-Summe (Oberkategorie). `forecast_entry_details` werden **nicht** exportiert. Prüfen, dass die KST→Mutter-Konsolidierung (`ForecastRowSource`) unverändert nur auf die Zeilensumme greift.

## 5. Offene Detailfragen
- Verhalten, wenn eine aufgeschlüsselte Jahreszelle zusätzlich auf Monatsebene „grob verteilt" bearbeitet wird — Vorrang-Regel definieren (vermutlich: Aufschlüsselung dominiert die betroffene Ebene).
- Historie/Changelog (`forecast_changes`) für Detail-Einträge mitschreiben?
- Rechte: wer darf aufschlüsseln vs. nur Summe sehen.

## 5b. Umgesetzte Teile (2026-10-26)

- **Migration** `2026_10_26_100015_create_forecast_entry_details_table.php` + Model
  `ForecastEntryDetail` (team/plan/row/bucket/channel/level/label/value signiert/ordinal).
- **PlanService:** `getBreakdown`, `setBreakdown` (ersetzt Unterzeilen, pflegt Zellwert = Σ,
  loggt Change-Op `breakdown` mit payload), `breakdownCells` (UI-Set). Leere Liste hebt die
  Aufschlüsselung auf (letzter Summenwert bleibt als Direktwert). `setCell`/`clearCell`
  um die Invariante ergänzt: direkte Zelle gesperrt bzw. Unterzeilen mit gelöscht.
- **CellEditability:** neuer Zustand `breakdown` (nicht direkt tippbar, Modal erreichbar).
- **PlanView (Livewire):** Properties + `openBreakdown/addBreakdownItem/removeBreakdownItem/
  saveBreakdown/closeBreakdown/breakdownSum`; `breakdownCells` ans View-Array.
- **Blade:** Marker + Read-only auf aufgeschlüsselten Zellen, Hover-Auslöser auf offenen
  Eingabezellen, Modal (Label+Betrag, Live-Summe, Entlastung negativ=grün).

**Reconciliation:** keine Änderung nötig (Zellwert trägt bereits die Summe). **Export-Guard:**
greift per Konstruktion — die Unterzeilen liegen in einer eigenen Tabelle, die nur der Service
liest; Rollup (`OrgRollup`) nutzt den reconciled Zellwert, ein DATEV/CSV-Export existiert im
Package noch nicht.

**Offen bis Live:** Migration auf dem Server ausführen; Live-Klick-Test (Zelle aufschlüsseln →
Summe oben läuft mit, Jahr→Monat verteilt, Entlastung grün).

## 6. Empfehlung zum Vorgehen
- **Nicht** im v1-Rollout (erster Planer-Durchlauf Rainer) — kein Mehrwert für den ersten Tipp-Durchgang, vergrößert die Fehlerfläche.
- Kandidat für die **Rolling-Forecast-Runde**, weil „woraus setzt sich die Zahl zusammen" dort ohnehin aus den HGK-Rechnungen (Rechnungs-/Veranstaltungsebene) kommt — teils als Ist-Frage, nicht nur Hand-Pflege.
- **Zuerst wertvoll für die eigene Planung** (BHG/Shared Service) — dort als erstes nutzbar machen, Feedback holen, dann breit ausrollen.

**Geschätzter Umbau:** 1 Migration + 1 Model, kleine Reconciliation-Anbindung (Node-Aufbau), 1 Livewire-Modal, Export-Guard. Engine-Mathematik unverändert.
