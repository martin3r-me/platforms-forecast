# Platform Forecast

Modul für **abstrakte, zahlengetriebene Planungen** in der Platform — Szenario-
und Modellrechnung.

> Status: **leeres Grundgerüst**. Service Provider, Config, Routing und ein leeres
> Dashboard sind angelegt. Die eigentliche Planungs-/Modellierungs-UI folgt.

## Struktur

```
forecast/
├── composer.json                      # Package: martin3r/platforms-forecast
├── config/
│   └── forecast.php                   # Routing, Navigation, Sidebar
├── resources/
│   └── views/livewire/
│       ├── dashboard.blade.php        # Leeres Dashboard
│       └── sidebar.blade.php          # Modul-Sidebar
├── routes/
│   └── web.php                        # /forecast → Dashboard
└── src/
    ├── ForecastServiceProvider.php    # Modul-Registrierung (PlatformCore)
    └── Livewire/
        ├── Dashboard.php
        └── Sidebar.php
```

- **Namespace:** `Platform\Forecast\`
- **Modul-Key / Route-Prefix:** `forecast`
- **View-Namespace:** `forecast::`

## In eine Instanz einbinden

Pro Instanz (z. B. `instances/demo`) in der `composer.json`:

```jsonc
"require": {
  "martin3r/platforms-forecast": "dev-main"
},
"repositories": [
  { "type": "path", "url": "/Users/martin3r/Platforms/platform/modules/forecast" }
]
```

Danach `composer update martin3r/platforms-forecast` und ggf.
`php artisan config:clear && php artisan route:clear`.

## Nächste Schritte

- Datenmodell für Planungen/Szenarien (Migrationen unter `database/migrations/`)
- Planungs-UI (Livewire) statt leerem Dashboard
- Team-basierte Filterung (`$user->currentTeam->id`)

Referenz-Module für komplexere Patterns: `platform/modules/planner`,
`platform/modules/finance`, `platform/modules/okr`.
