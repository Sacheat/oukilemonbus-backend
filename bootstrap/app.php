<?php

use App\Exceptions\ApiErrorMessage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Pas de page de login côté backend : un invité sur /api/* reçoit un 401 JSON (ApiErrorMessage), jamais une redirection.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');

        // Routes réservées aux invités (register, login, forgot-password) appelées avec une session ouverte :
        // 409 JSON plutôt qu'une redirection, que la SPA suivrait vers GET / (503). RedirectIfAuthenticated
        // n'accepte pas null comme cible : on lève directement l'erreur, rendue en JSON plus bas.
        $middleware->redirectUsersTo(function (Request $request): string {
            if ($request->is('api/*')) {
                abort(409, 'Vous êtes déjà connecté. Déconnectez-vous pour continuer.');
            }

            return '/';
        });

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toute réponse d'erreur sous /api/* est en JSON, même sans header Accept (plus de redirection HTML).
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        // Messages d'erreur de l'API en français (voir App\Exceptions\ApiErrorMessage).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => ApiErrorMessage::for(401, $e->getMessage())], 401);
            }
        });

        // Lien signé invalide ou expiré (middleware signed) : message adapté à la route.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => ApiErrorMessage::invalidSignature($request->route()?->getName())], 403);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e->getStatusCode();

                return response()->json(
                    ['message' => ApiErrorMessage::for($status, $e->getMessage())],
                    $status,
                    $e->getHeaders()
                );
            }
        });
    })->create();
