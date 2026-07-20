{{--
    Forecast — Dashboard

    Leeres Modul-Grundgerüst. Content-Bereich ist bewusst minimal gehalten
    und wartet auf die eigentliche Planungs-/Modellierungs-UI.

    Shell-Komponenten (shared, unverändert):
    x-ui-page, x-ui-page-navbar, x-ui-page-actionbar, x-ui-page-container
--}}

<x-ui-page>
    {{-- Navbar --}}
    <x-slot name="navbar">
        <x-ui-page-navbar title="Forecast" />
    </x-slot>

    {{-- Actionbar --}}
    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Forecast', 'href' => route('forecast.dashboard'), 'icon' => 'presentation-chart-line'],
        ]" />
    </x-slot>

    {{-- Hauptinhalt --}}
    <x-ui-page-container>
        <div class="flex flex-col items-center justify-center text-center py-24">
            <div class="flex items-center justify-center w-14 h-14 rounded-2xl bg-violet-500/10 mb-5">
                @svg('heroicon-o-presentation-chart-line', 'w-7 h-7 text-violet-500')
            </div>
            <h1 class="text-xl font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-2">
                Forecast
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md">
                Abstrakte, zahlengetriebene Planungen. Das Modul ist angelegt und leer —
                die Planungs-UI folgt.
            </p>
        </div>
    </x-ui-page-container>
</x-ui-page>
