<?php

use App\Http\Controllers\MapController;
use App\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::get('/', TimelineController::class)->name('timeline');
Route::get('/map', MapController::class)->name('map');
