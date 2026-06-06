<?php

declare(strict_types=1);

namespace App\Services\DevTools;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

final class DebugService
{
    /**
     * Inspecte un utilisateur par email.
     * Retourne null si l'utilisateur est introuvable.
     */
    public function inspectUser(string $email): ?array
    {
        /** @var class-string<\Illuminate\Foundation\Auth\User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        $user = $userModel::where('email', $email)->first();

        if ($user === null) {
            return null;
        }

        $firstName = trim((string) ($user->first_name ?? ''));
        $lastName  = trim((string) ($user->last_name ?? ''));
        $fullName  = trim($firstName . ' ' . $lastName) ?: ($user->name ?? '—');

        return [
            'id'             => $user->id,
            'email'          => $user->email,
            'name'           => $fullName,
            'email_verified' => $user->email_verified_at !== null,
            'is_active'      => (bool) ($user->is_active ?? true),
            'roles'          => method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->values()->all()
                : [],
            'permissions'    => method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')->sort()->values()->all()
                : [],
            'organizations'  => method_exists($user, 'organizations')
                ? $user->organizations()->pluck('name')->all()
                : [],
            'created_at'     => $user->created_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * Construit la matrice rôles × permissions.
     * Retourne [roles[], permissions[], matrix[role => [permissions]]]
     */
    public function permissionsMatrix(): array
    {
        try {
            $roles      = Role::with('permissions')->get()->keyBy('name');
            $allPerms   = Permission::orderBy('name')->pluck('name')->all();

            $matrix = $roles
                ->map(fn ($role): array => $role->permissions->pluck('name')->all())
                ->all();

            return [
                'roles'       => $roles->keys()->all(),
                'permissions' => $allPerms,
                'matrix'      => $matrix,
            ];
        } catch (Throwable $e) {
            return [
                'roles'       => [],
                'permissions' => [],
                'matrix'      => [],
                'error'       => $e->getMessage(),
            ];
        }
    }

    /**
     * Retourne tous les utilisateurs groupés par rôle principal.
     * Format : ['Libellé rôle' => [['email', 'name', 'roles'], ...], ...]
     */
    public function allUsers(): array
    {
        /** @var class-string<\Illuminate\Foundation\Auth\User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        $roleOrder = ['admin', 'agent_communal', 'responsable_organisation', 'chauffeur', 'user'];

        $grouped = array_fill_keys($roleOrder, []);
        $grouped['autre'] = [];

        $users = $userModel::with('roles')->orderBy('email')->get();

        foreach ($users as $user) {
            $primaryRole = method_exists($user, 'getRoleNames')
                ? ($user->getRoleNames()->first() ?? 'autre')
                : 'autre';

            $key = in_array($primaryRole, $roleOrder, true) ? $primaryRole : 'autre';

            $firstName = trim((string) ($user->first_name ?? ''));
            $lastName  = trim((string) ($user->last_name ?? ''));
            $fullName  = trim($firstName . ' ' . $lastName) ?: ($user->name ?? $user->email);

            $grouped[$key][] = [
                'email' => $user->email,
                'name'  => $fullName,
                'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->all() : [],
            ];
        }

        // Supprime les groupes vides et traduit les clés en libellés lisibles
        $labels = [
            'admin'                    => 'Admin',
            'agent_communal'           => 'Agent communal',
            'responsable_organisation' => 'Responsable organisation',
            'chauffeur'                => 'Chauffeur',
            'user'                     => 'Utilisateur',
            'autre'                    => 'Sans rôle',
        ];

        $result = [];
        foreach ($grouped as $roleKey => $members) {
            if ($members === []) {
                continue;
            }
            $result[$labels[$roleKey] ?? $roleKey] = $members;
        }

        return $result;
    }

    /**
     * Génère un token Sanctum temporaire pour l'email donné.
     * Retourne null si l'utilisateur est introuvable.
     */
    public function generateToken(string $email): ?array
    {
        /** @var class-string<\Illuminate\Foundation\Auth\User> $userModel */
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        $user = $userModel::where('email', $email)->first();

        if ($user === null) {
            return null;
        }

        $tokenName = 'dev-toolbox-' . now()->format('YmdHis');
        $token     = $user->createToken($tokenName);

        return [
            'email'      => $email,
            'token_name' => $tokenName,
            'token'      => $token->plainTextToken,
        ];
    }
}
