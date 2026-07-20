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

/**
 * Dashboard Route
 *
 * Hauptübersicht des Moduls
 */
Route::get('/', Dashboard::class)->name('forecast.dashboard');

/**
 * Weitere Routes hinzufügen:
 * 
 * Route::get('/entities', Entity\Index::class)->name('forecast.entities.index');
 * Route::get('/entities/{entity}', Entity\Show::class)->name('forecast.entities.show');
 */
