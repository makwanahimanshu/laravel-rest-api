<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\JWTAuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('register', [JWTAuthController::class, 'register']);
Route::post('login', [JWTAuthController::class, 'login']);
Route::post('logout', [JWTAuthController::class, 'logout']);
Route::post('edit-fcm', [JWTAuthController::class, 'UpdateFcm']);
Route::get('verify-email/{token}', [JWTAuthController::class, 'verifyEmail'])->name('verification.verify');
Route::post('verify-staff-user/{token}', [JWTAuthController::class, 'verifyStaffUser'])->name('verification.verify'); //verify staff user api
Route::post('resend-verification-link', [JWTAuthController::class, 'resendVerificationLink']);