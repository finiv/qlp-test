<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Ship it.');
})->purpose('Display an inspiring quote');
