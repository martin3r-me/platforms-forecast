<?php

/**
 * Forecast Web Routes
 * 
 * Diese Datei definiert alle Web-Routes für das Modul.
 * 
 * WICHTIG FÜR LLMs:
 * - Routes werden automatisch mit dem Modul-Prefix versehen (aus Config)
 * - Middleware wird automatisch hinzugefügt (web, auth, etc.)
 * - Route-Namen sollten mit dem Modul-Prefix beginnen
 * 
 * BEISPIEL:
 * Route::get('/', Dashboard::class)->name('forecast.dashboard');
 * 
 * Wird zu: /forecast/ (wenn prefix = 'forecast')
 * 
 * @see Platform\Core\Routing\ModuleRouter für Details
 */

use Platform\Forecast\Livewire\Dashboard;
use Platform\Forecast\Livewire\PlanIndex;
use Platform\Forecast\Livewire\PlanView;
use Platform\Forecast\Livewire\Settings;

/**
 * Dashboard
 */
Route::get('/', Dashboard::class)->name('forecast.dashboard');

/**
 * Planungen (read-only): Liste + zoombare Grid-Ansicht
 */
Route::get('/plans', PlanIndex::class)->name('forecast.plans.index');
Route::get('/plans/{uuid}', PlanView::class)->name('forecast.plans.show');

/**
 * Einstellungen (read-only): Konfig-Modelle als Index-Seiten
 */
Route::get('/settings/{section?}', Settings::class)->name('forecast.settings');
