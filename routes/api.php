<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\QuestionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Questions API Routes
Route::post('/upload-pdf', [QuestionController::class, 'uploadPdf']);
Route::get('/questions', [QuestionController::class, 'index']);
Route::post('/questions/{id}/add-image', [QuestionController::class, 'addImage']);
Route::get('/progress/{jobId}', [QuestionController::class, 'getProgress']);
