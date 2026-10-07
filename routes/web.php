<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

// Acesso pelo link pessoal enviado por WhatsApp.
Route::get('/entrar', [AuthController::class, 'login'])->name('login');
Route::get('/entrar/{token}', [AuthController::class, 'loginWithLink'])->name('login.link')->middleware('throttle:20,1');
Route::post('/sair', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route(auth()->user()->isAdmin() ? 'contents.index' : 'review.index'))->name('home');

    // Hub de aprovação (Bruna e Carla).
    Route::get('/aprovar', [ReviewController::class, 'index'])->name('review.index');
    Route::get('/aprovar/{content}', [ReviewController::class, 'show'])->name('review.show');
    Route::post('/aprovar/{content}', [ReviewController::class, 'store'])->name('review.store');

    // Prévia dos arquivos e anexos dos ajustes (só para quem está logado).
    Route::get('/media/{driveFile}', [MediaController::class, 'file'])->name('media.file');
    Route::get('/media/{driveFile}/capa', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::get('/media/ajuste/{review}/audio', [MediaController::class, 'audio'])->name('media.audio');
    Route::get('/media/ajuste/anexo/{attachment}', [MediaController::class, 'attachment'])->name('media.attachment');

    // Área da Ana.
    Route::middleware('admin')->group(function () {
        Route::get('/conteudos', [ContentController::class, 'index'])->name('contents.index');
        Route::post('/conteudos/para-planilha', [ContentController::class, 'moveMany'])->name('contents.move-many');
        Route::post('/conteudos/aprovacao', [ContentController::class, 'approvalMany'])->name('contents.approval-many');
        // "Avisar no WhatsApp" agora fica dentro de Pessoas e acessos.
        Route::get('/conteudos/avisar', fn () => redirect()->route('people.index'))->name('contents.notify');
        Route::get('/conteudos/{content}', [ContentController::class, 'show'])->name('contents.show');
        Route::put('/conteudos/{content}', [ContentController::class, 'update'])->name('contents.update');
        Route::post('/conteudos/{content}/{action}', [ContentController::class, 'action'])->name('contents.action');

        Route::get('/pessoas', [PeopleController::class, 'index'])->name('people.index');
        Route::post('/pessoas', [PeopleController::class, 'store'])->name('people.store');
        Route::post('/pessoas/{user}/link', [PeopleController::class, 'link'])->name('people.link');

        Route::get('/pastas', [FolderController::class, 'index'])->name('folders.index');
        Route::post('/pastas', [FolderController::class, 'store'])->name('folders.store');
        Route::post('/pastas/{folder}/alternar', [FolderController::class, 'toggle'])->name('folders.toggle');
        Route::post('/pastas/importar', [FolderController::class, 'backfill'])->name('folders.backfill');
    });
});
