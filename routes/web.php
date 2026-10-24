<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/lab');
});

require __DIR__.'/lab.php';
