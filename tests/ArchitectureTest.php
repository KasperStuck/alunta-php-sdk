<?php

arch('the Alunta SDK uses no framework, HTTP client library, Filament, Livewire or application code')
    ->expect('KasperStuck\Alunta\Sdk')
    ->not->toUse(['Illuminate', 'GuzzleHttp', 'Filament', 'Livewire', 'App', 'KasperStuck\Alunta\Laravel', 'KasperStuck\Alunta\Filament']);
