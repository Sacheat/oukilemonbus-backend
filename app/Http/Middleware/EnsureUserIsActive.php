<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->active) {
            return response()->json(['message' => 'Ce compte a été désactivé.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
