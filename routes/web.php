<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\PasswordResetController;
use Illuminate\Support\Facades\Mail;

Route::get('/', function () {
    return view('welcome');
});


