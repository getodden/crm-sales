<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Odden\Core\Support\RouteGroup;
use Odden\Sales\Http\Controllers\QuoteAcceptanceController;
use Odden\Sales\Http\Controllers\SalesMeetingBookingController;
use Odden\Sales\Http\Controllers\SalesMeetingCancellationController;

Route::group(RouteGroup::attributes('odden-sales.routes.web'), function (): void {
    // Quotes public e-sign portal
    Route::get('/quotes/{token}', [QuoteAcceptanceController::class, 'show'])->name('odden.quotes.show');
    Route::post('/quotes/{token}/accept', [QuoteAcceptanceController::class, 'accept'])
        ->middleware('throttle:odden-public')
        ->name('odden.quotes.accept');

    // Sales reps meeting scheduler
    Route::get('/meet/{slug}', [SalesMeetingBookingController::class, 'show'])->name('odden.meetings.show');
    Route::post('/meet/{slug}/book', [SalesMeetingBookingController::class, 'book'])
        ->middleware('throttle:odden-public')
        ->name('odden.meetings.book');

    // The invitee's cancel page: signed URL, GET only shows, POST cancels.
    Route::get('/meet/bookings/{uid}/cancel', [SalesMeetingCancellationController::class, 'show'])->name('odden.meetings.cancel.show');
    Route::post('/meet/bookings/{uid}/cancel', [SalesMeetingCancellationController::class, 'cancel'])
        ->middleware('throttle:odden-public')
        ->name('odden.meetings.cancel');
});
