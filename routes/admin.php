<?php

use DreamLayer\UnoPIM\Http\Controllers\CatalogController;
use DreamLayer\UnoPIM\Http\Controllers\SettingsController;
use DreamLayer\UnoPIM\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware('admin')->prefix(config('app.admin_url').'/dreamlayer')->name('dreamlayer.')->group(function () {
    Route::get('choose', [CatalogController::class, 'page'])->name('choose');
    Route::get('catalog/options', [CatalogController::class, 'options'])->name('catalog.options');
    Route::get('catalog', [CatalogController::class, 'index'])->name('catalog');
    Route::get('catalog/{product}/{imageAttribute}/{index}', [CatalogController::class, 'image'])->whereNumber('product')->whereNumber('index')->name('catalog.image');
    Route::get('settings', [SettingsController::class, 'show'])->name('settings');
    Route::get('settings/status', [SettingsController::class, 'status'])->middleware('throttle:10,1,dreamlayer-status:')->name('settings.status');
    Route::post('settings', [SettingsController::class, 'save'])->name('settings.save');
    Route::post('chat/open', [SettingsController::class, 'openChat'])->name('chat.open');
    Route::post('connect', [SettingsController::class, 'connect'])->middleware('throttle:10,1,dreamlayer-connect:')->name('connect');
    Route::post('connect/poll', [SettingsController::class, 'poll'])->middleware('throttle:15,1,dreamlayer-poll:')->name('connect.poll');
    Route::post('disconnect', [SettingsController::class, 'disconnect'])->name('disconnect');
    Route::get('batches/{id}', [WorkflowController::class, 'batch'])->whereUuid('id')->name('batch');
    Route::get('workflows', [WorkflowController::class, 'index'])->name('results');
    Route::get('workflows/{id}', [WorkflowController::class, 'show'])->whereUuid('id')->name('workflow');
    Route::get('workflows/{id}/image', [WorkflowController::class, 'image'])->whereUuid('id')->name('image');
    Route::post('workflows/{id}/apply', [WorkflowController::class, 'apply'])->whereUuid('id')->name('apply');
});
