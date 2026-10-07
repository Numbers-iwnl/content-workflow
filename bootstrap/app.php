<?php

use App\Http\Middleware\EnsureAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => EnsureAdmin::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

/*
 * No servidor, os dados ficam numa pasta irmã do código: conteudos-data/
 * (.env, database.sqlite e storage/ com a chave do Google, áudios e prints).
 * Assim uma atualização é só apagar conteudos-app e subir o zip novo,
 * sem nunca tocar nos dados. Sem essa pasta (no computador), tudo fica
 * dentro do projeto, como num Laravel normal.
 */
$data = dirname(__DIR__, 2).'/conteudos-data';

if (is_dir($data)) {
    define('APP_DATA_PATH', $data);
    $app->useEnvironmentPath($data);
    $app->useStoragePath($data.'/storage');
}

return $app;
