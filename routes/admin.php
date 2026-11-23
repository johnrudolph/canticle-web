<?php

use App\Http\Controllers\Admin\CardCommentController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GlyphController;
use App\Http\Controllers\Admin\GolemCardController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\RelicController;
use App\Http\Controllers\Admin\RuleBookController;
use App\Http\Middleware\EnsureIsAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureIsAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('rulebook', [RuleBookController::class, 'index'])->name('rulebook.index');
    Route::put('rulebook', [RuleBookController::class, 'update'])->name('rulebook.update');

    Route::post('comments', [CardCommentController::class, 'store'])->name('comments.store');
    Route::put('comments/{comment}/resolve', [CardCommentController::class, 'resolve'])->name('comments.resolve');

    foreach ([
        ['locations', LocationController::class],
        ['relics', RelicController::class],
        ['glyphs', GlyphController::class],
        ['golem-cards', GolemCardController::class],
    ] as [$prefix, $controller]) {
        Route::get($prefix, [$controller, 'index'])->name("{$prefix}.index");
        Route::post($prefix, [$controller, 'store'])->name("{$prefix}.store");
        Route::get("{$prefix}/{id}", [$controller, 'show'])->name("{$prefix}.show");
        Route::put("{$prefix}/{id}", [$controller, 'update'])->name("{$prefix}.update");
        Route::delete("{$prefix}/{id}", [$controller, 'destroy'])->name("{$prefix}.destroy");
    }
});
