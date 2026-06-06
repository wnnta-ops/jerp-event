<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('modules.bankloan.index'))->name('index');
