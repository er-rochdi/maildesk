<?php

use App\Http\Controllers\MessageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ResendWebhookController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::post('/webhooks/resend', ResendWebhookController::class)->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:120,1');
Route::post('/auth/register', [WorkspaceController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login', [WorkspaceController::class, 'login'])->middleware('throttle:10,1');
Route::middleware('auth')->group(function () {
    Route::get('/workspace/dashboard', [DashboardController::class, 'show']);
    Route::get('/workspace/admin/activity', [DashboardController::class, 'activity']);
    Route::get('/workspace/mailboxes/{mailbox}/messages', [MessageController::class, 'index']);
    Route::post('/workspace/mailboxes/{mailbox}/messages', [MessageController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/workspace/mailboxes/{mailbox}/sync', [MessageController::class, 'sync'])->middleware('throttle:3,1');
    Route::get('/workspace/messages/{message}', [MessageController::class, 'show']);
    Route::post('/workspace/messages/{message}/read', [MessageController::class, 'read']);
    Route::get('/workspace/attachments/{attachment}', [MessageController::class, 'attachment']);
    Route::get('/auth/me', [WorkspaceController::class, 'me']);
    Route::post('/auth/logout', [WorkspaceController::class, 'logout']);
    Route::get('/workspace/users', [WorkspaceController::class, 'users']);
    Route::post('/workspace/users', [WorkspaceController::class, 'createUser']);
    Route::get('/workspace/mailboxes', [WorkspaceController::class, 'mailboxes']);
    Route::post('/workspace/mailboxes', [WorkspaceController::class, 'createMailbox']);
    Route::put('/workspace/mailboxes/{mailbox}', [WorkspaceController::class, 'updateMailbox']);
    Route::put('/workspace/mailboxes/{mailbox}/access', [WorkspaceController::class, 'updateAccess']);
});
