<?php

use App\Http\Controllers\MessageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ResendWebhookController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\MessageTeamController;
use App\Http\Middleware\EnsureActiveAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::get('/reset-password/{token}', fn () => response()->view('app')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store'))->name('password.reset');
Route::post('/auth/forgot-password', [AccountController::class, 'forgotPassword'])->middleware('throttle:5,1,password-forgot:');
Route::post('/auth/reset-password', [AccountController::class, 'resetPassword'])->middleware('throttle:10,1,password-reset:');
Route::post('/webhooks/resend', ResendWebhookController::class)->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:120,1');
Route::post('/auth/register', [WorkspaceController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login', [WorkspaceController::class, 'login'])->middleware('throttle:10,1');
Route::middleware(['auth', EnsureActiveAccount::class])->group(function () {
    Route::put('/account/profile', [AccountController::class, 'profile']);
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:5,1,password-change:');
    Route::put('/workspace/users/{user}', [AccountController::class, 'update']);
    Route::put('/workspace/users/{user}/status', [AccountController::class, 'status']);
    Route::get('/workspace/dashboard', [DashboardController::class, 'show']);
    Route::get('/workspace/admin/activity', [DashboardController::class, 'activity']);
    Route::get('/workspace/mailboxes/{mailbox}/messages', [MessageController::class, 'index']);
    Route::post('/workspace/mailboxes/{mailbox}/messages', [MessageController::class, 'store'])->middleware('throttle:20,1,mail-send:');
    Route::post('/workspace/mailboxes/{mailbox}/sync', [MessageController::class, 'sync'])->middleware('throttle:3,1,mail-sync:');
    Route::get('/workspace/messages/{message}', [MessageController::class, 'show']);
    Route::put('/workspace/messages/{message}/folder', [MessageController::class, 'folder']);
    Route::get('/workspace/messages/{message}/team', [MessageTeamController::class, 'show']);
    Route::put('/workspace/messages/{message}/team', [MessageTeamController::class, 'update']);
    Route::post('/workspace/messages/{message}/notes', [MessageTeamController::class, 'note']);
    Route::post('/workspace/messages/{message}/read', [MessageController::class, 'read']);
    Route::get('/workspace/attachments/{attachment}', [MessageController::class, 'attachment']);
    Route::get('/auth/me', [WorkspaceController::class, 'me']);
    Route::post('/auth/logout', [WorkspaceController::class, 'logout']);
    Route::get('/workspace/users', [WorkspaceController::class, 'users']);
    Route::post('/workspace/users', [WorkspaceController::class, 'createUser']);
    Route::put('/workspace/users/{user}/permissions', [WorkspaceController::class, 'updatePermissions']);
    Route::get('/workspace/mailboxes', [WorkspaceController::class, 'mailboxes']);
    Route::post('/workspace/mailboxes', [WorkspaceController::class, 'createMailbox']);
    Route::put('/workspace/mailboxes/{mailbox}', [WorkspaceController::class, 'updateMailbox']);
    Route::put('/workspace/mailboxes/{mailbox}/access', [WorkspaceController::class, 'updateAccess']);
});
