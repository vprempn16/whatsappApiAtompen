<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SpeechController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/transcribe', [SpeechController::class, 'index']);
Route::post('/transcribe-audio', [SpeechController::class, 'transcribe']);
