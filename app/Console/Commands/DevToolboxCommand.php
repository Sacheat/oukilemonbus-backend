<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DevTools\CustomCommandsService;
use App\Services\DevTools\DatabaseService;
use App\Services\DevTools\DebugService;
use App\Services\DevTools\DefensePrepService;
use App\Services\DevTools\MaintenanceService;
use App\Services\DevTools\ScheduleInspectorService;
use Illuminate\Console\Command;

class DevToolboxCommand extends Command
{
    protected $signature = 'dev:toolbox';

    protected $description = 'Boîte à outils de développement — maintenance, debug, défense, backups';

    public function __construct(
        private readonly DefensePrepService       $defenseService,
        private readonly CustomCommandsService    $customCommandsService,
        private readonly ScheduleInspectorService $scheduleService,
        private readonly DebugService             $debugService,
        private readonly MaintenanceService       $maintenanceService,
        private readonly DatabaseService          $databaseService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $enabled = (bool) config('dev_toolbox.enabled', false);
        $guard   = (array) config('dev_toolbox.env_guard', ['local']);

        if (! $enabled || ! in_array(app()->environment(), $guard, true)) {
            $this->error('Dev Toolbox désactivé hors environnement local.');
            $this->line('  Pour activer : DEV_TOOLBOX_ENABLED=true dans le .env (APP_ENV=local uniquement)');

            return self::FAILURE;
        }

        $menuItems = $this->buildMenuItems();

        do {
            $this->printToolbox($menuItems);
            $choice   = trim((string) $this->ask('  Votre choix'));
            $continue = $this->dispatch($choice, $menuItems);

            if ($continue) {
                $this->newLine();
                $this->ask('  <fg=gray>↩  Entrée pour revenir au menu</>');
            }
        } while ($continue);

        $this->newLine();
        $this->line('  <fg=gray>Au revoir.</>');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Construction et affichage du menu
    // ─────────────────────────────────────────────────────────────────────────

    private function buildMenuItems(): array
    {
        $customCommands = $this->customCommandsService->list();
        $customCount    = count($customCommands);
        $items          = [];

        $items[1] = ['label' => 'Préparer la démo',                  'section' => 'defense'];
        $items[2] = ['label' => 'Règles métier actives',              'section' => 'defense'];
        $items[3] = ['label' => 'Architecture du projet',             'section' => 'defense'];
        $items[4] = ['label' => 'Statistiques de la base',            'section' => 'defense'];
        $items[5] = ['label' => 'Lancer les tests',                   'section' => 'defense'];
        $items[6] = ['label' => 'Catalogue des APIs',                 'section' => 'defense'];

        foreach ($customCommands as $i => $cmd) {
            $items[7 + $i] = [
                'label'        => $cmd['label'],
                'section'      => 'custom',
                'custom_index' => $i,
            ];
        }

        $n = 7 + $customCount;

        $items[$n]     = ['label' => 'Tâches planifiées (schedule:list)',   'section' => 'planning'];
        $items[$n + 1] = ['label' => 'Inspecter un utilisateur',            'section' => 'debug'];
        $items[$n + 2] = ['label' => 'Matrice rôles × permissions',         'section' => 'debug'];
        $items[$n + 3] = ['label' => 'Générer un token Sanctum temporaire', 'section' => 'debug'];
        $items[$n + 4] = ['label' => 'Réparer rôles / permissions',         'section' => 'maintenance'];
        $items[$n + 5] = ['label' => 'Nettoyer caches Laravel',             'section' => 'maintenance'];
        $items[$n + 6] = ['label' => 'Valider emails fictifs',              'section' => 'maintenance'];
        $items[$n + 7] = ['label' => 'Reset complet démo  ⚠',              'section' => 'database'];

        return $items;
    }

    private function printToolbox(array $menuItems): void
    {
        $appName = config('app.name', 'Application');
        $env     = app()->environment();
        $php     = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $sections = [
            'defense'     => ['icon' => '🎯', 'title' => 'DÉFENSE / PRÉSENTATION'],
            'custom'      => ['icon' => '⭐', 'title' => 'NOS COMMANDES CUSTOM'],
            'planning'    => ['icon' => '🗓️', 'title' => 'PLANIFICATION'],
            'debug'       => ['icon' => '🔍', 'title' => 'DEBUG'],
            'maintenance' => ['icon' => '🔧', 'title' => 'MAINTENANCE'],
            'database'    => ['icon' => '🗄️', 'title' => 'BASE DE DONNÉES'],
        ];

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>╔══════════════════════════════════════════════════════════╗</>');
        $this->line("  <fg=cyan;options=bold>║</>  <options=bold>{$appName} — Dev Toolbox</>                              <fg=cyan;options=bold>║</>");
        $this->line("  <fg=cyan;options=bold>║</>  <fg=gray>Environnement : {$env}  •  PHP {$php}  •  Laravel 13</>   <fg=cyan;options=bold>║</>");
        $this->line('  <fg=cyan;options=bold>╚══════════════════════════════════════════════════════════╝</>');
        $this->newLine();

        $currentSection = null;

        foreach ($menuItems as $num => $item) {
            if ($item['section'] !== $currentSection) {
                $currentSection = $item['section'];
                $s = $sections[$currentSection];
                $this->newLine();
                $this->line("  <options=bold>{$s['icon']}  {$s['title']}</>");
                $this->line('  ' . str_repeat('─', 58));
            }

            $numStr = str_pad((string) $num, 3, ' ', STR_PAD_LEFT);
            $this->line("  <fg=yellow>[{$numStr}]</>  {$item['label']}");
        }

        $this->newLine();
        $this->line('  <fg=yellow>[  0]</>  Quitter');
        $this->newLine();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dispatch
    // ─────────────────────────────────────────────────────────────────────────

    private function dispatch(string $choice, array $menuItems): bool
    {
        if ($choice === '0') {
            return false;
        }

        $num = (int) $choice;

        if (! isset($menuItems[$num])) {
            $this->error("  Choix invalide : \"{$choice}\"");

            return true;
        }

        $item        = $menuItems[$num];
        $customCount = count($this->customCommandsService->list());
        $n           = 7 + $customCount;

        return match (true) {
            $item['section'] === 'defense' && $num === 1 => $this->actionPrepareDemo(),
            $item['section'] === 'defense' && $num === 2 => $this->actionBusinessRules(),
            $item['section'] === 'defense' && $num === 3 => $this->actionArchitecture(),
            $item['section'] === 'defense' && $num === 4 => $this->actionDbStats(),
            $item['section'] === 'defense' && $num === 5 => $this->actionRunTests(),
            $item['section'] === 'defense' && $num === 6 => $this->actionApiCatalog(),
            $item['section'] === 'custom'                => $this->runCustomCommand($item['custom_index']),
            $num === $n                                  => $this->actionSchedule(),
            $num === $n + 1                              => $this->actionInspectUser(),
            $num === $n + 2                              => $this->actionPermissionsMatrix(),
            $num === $n + 3                              => $this->actionGenerateToken(),
            $num === $n + 4                              => $this->actionRepairPermissions(),
            $num === $n + 5                              => $this->actionClearCaches(),
            $num === $n + 6                              => $this->actionValidateDemoEmails(),
            $num === $n + 7                              => $this->actionResetDemo(),
            default                                      => true,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions — Défense / Présentation
    // ─────────────────────────────────────────────────────────────────────────

    private function actionPrepareDemo(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🎯  Vérification pré-démo</>');
        $this->newLine();

        $badge  = static fn (string $s): string => match ($s) {
            'ok'    => '<fg=green>✓ OK</>',
            'warn'  => '<fg=yellow>⚠ WARN</>',
            default => '<fg=red>✗ ERROR</>',
        };
        $checks = $this->defenseService->prepareDemo();
        $rows   = [];

        foreach ($checks as $check) {
            $rows[] = [
                $check['label'],
                $badge($check['status']),
                $check['detail'],
                $check['hint'] ?? '—',
            ];
        }

        $this->table(['Vérification', 'Statut', 'Détail', 'Action si problème'], $rows);

        $allOk = collect($checks)->every(fn ($c) => $c['status'] === 'ok');
        $this->newLine();

        if ($allOk) {
            $this->line('  <fg=green;options=bold>✓  Tout est prêt. Bonne présentation !</>');
        } else {
            $this->line('  <fg=yellow;options=bold>⚠  Des points nécessitent votre attention avant la démo.</>');
        }

        return true;
    }

    private function actionBusinessRules(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>📋  Règles métier actives</>');
        $this->newLine();

        $rules = $this->defenseService->businessRules();

        if ($rules === []) {
            $this->warn('  Aucune règle configurée dans dev_toolbox.business_rules');

            return true;
        }

        $this->table(
            ['Règle', 'Valeur', 'Unité'],
            array_map(fn ($r) => [$r['label'], $r['value'], $r['unit']], $rules)
        );

        return true;
    }

    private function actionArchitecture(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🏗️  Architecture du projet</>');
        $this->newLine();

        $items = $this->defenseService->architecture();

        $this->table(
            ['Composant', 'Fichiers', 'Chemin'],
            array_map(fn ($i) => [$i['label'], $i['count'], $i['path']], $items)
        );

        return true;
    }

    private function actionDbStats(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>📊  Statistiques base de données</>');
        $this->newLine();

        $stats = $this->defenseService->dbStats();

        $this->table(
            ['Table / Entité', 'Enregistrements'],
            array_map(fn ($s) => [$s['label'], $s['count']], $stats)
        );

        return true;
    }

    private function actionRunTests(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🧪  Lancement des tests</>');
        $this->newLine();

        $this->call('test');

        return true;
    }

    private function actionApiCatalog(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🔌  Catalogue des APIs</>');

        $catalog = $this->defenseService->apiCatalog();

        $this->newLine();
        $this->line('  <options=bold>APIs internes — groupées par module</>');
        $this->line('  ' . str_repeat('─', 58));

        if ($catalog['internal'] === []) {
            $this->line('  <fg=gray>Aucune route API trouvée.</>');
        }

        foreach ($catalog['internal'] as $module => $routes) {
            $this->newLine();
            $this->line("  <fg=cyan;options=bold>{$module}</>");
            $this->table(
                ['Méthode', 'URI', 'Permission requise'],
                array_map(fn ($r) => [$r['method'], $r['uri'], $r['permission']], $routes)
            );
        }

        $this->newLine();
        $this->line('  <options=bold>APIs externes utilisées</>');
        $this->line('  ' . str_repeat('─', 58));

        foreach ($catalog['external'] as $api) {
            $this->newLine();
            $this->line("  <fg=cyan;options=bold>{$api['name']}</>");
            $this->line("  <fg=gray>URL          :</> {$api['url']}");
            $this->line("  <fg=gray>Clé env      :</> {$api['env_key']}");
            $this->line("  <fg=gray>Utilisé pour :</> {$api['used_for']}");

            $this->newLine();
            $this->line('  <fg=gray>Requête :</>');
            $this->table(['Paramètre', 'Valeur'], array_map(
                fn ($k, $v) => [$k, $v],
                array_keys($api['request']),
                array_values($api['request'])
            ));

            $this->line('  <fg=gray>Champs utilisés dans la réponse :</>');
            $this->table(['Champ JSON', 'Contenu'], array_map(
                fn ($k, $v) => [$k, $v],
                array_keys($api['response_fields']),
                array_values($api['response_fields'])
            ));

            if (! empty($api['fallback'])) {
                $this->line("  <fg=yellow>⚠  Fallback :</> {$api['fallback']}");
            }
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions — Commandes custom
    // ─────────────────────────────────────────────────────────────────────────

    private function runCustomCommand(int $index): bool
    {
        $def = $this->customCommandsService->get($index);

        if ($def === null) {
            $this->error('Commande introuvable.');

            return true;
        }

        $this->newLine();
        $this->line("  <options=bold>⭐  {$def['label']}</>");
        $this->newLine();

        if ($this->customCommandsService->isInteractive($index)) {
            $useCustomDate = $this->confirm('  Utiliser une date personnalisée ? (défaut : demain)', false);

            if ($useCustomDate) {
                $date = $this->ask('  Date de service (YYYY-MM-DD)');
                $this->call($def['command'], array_merge($def['options'] ?? [], ['serviceDate' => $date]));
            } else {
                $this->call($def['command'], $def['options'] ?? []);
            }

            return true;
        }

        $this->call($def['command'], $def['options'] ?? []);

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions — Planification
    // ─────────────────────────────────────────────────────────────────────────

    private function actionSchedule(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🗓️  Tâches planifiées</>');
        $this->newLine();

        $this->line($this->scheduleService->list());

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions — Debug
    // ─────────────────────────────────────────────────────────────────────────

    private function actionInspectUser(): bool
    {
        $grouped = $this->debugService->allUsers();

        if ($grouped === []) {
            $this->newLine();
            $this->warn('  Aucun utilisateur en base.');

            return true;
        }

        // Entrée sentinelle en tête : index 0 → null → sortie de la boucle
        $choices = ['↩  Retour au menu principal'];
        $emails  = [null];

        foreach ($grouped as $roleLabel => $members) {
            foreach ($members as $member) {
                $choices[] = sprintf('[%-28s]  %-30s  <%s>', $roleLabel, $member['name'], $member['email']);
                $emails[]  = $member['email'];
            }
        }

        while (true) {
            $this->newLine();
            $this->line('  <options=bold>🔍  Inspection utilisateur — choisissez un compte</>');
            $this->newLine();

            $index = array_search($this->choice('  Utilisateur', $choices), $choices, true);

            if ($emails[$index] === null) {
                break;
            }

            $data = $this->debugService->inspectUser($emails[$index]);

            if ($data === null) {
                $this->error("  Utilisateur introuvable : {$emails[$index]}");
                continue;
            }

            $this->printUserDetail($data);
        }

        return true;
    }

    private function printUserDetail(array $data): void
    {
        $verified = $data['email_verified'] ? '<fg=green>Oui</>' : '<fg=red>Non</>';
        $active   = $data['is_active']      ? '<fg=green>Oui</>' : '<fg=red>Non</>';
        $roles    = implode(', ', $data['roles'])         ?: '—';
        $orgs     = implode(', ', $data['organizations']) ?: '—';

        $this->newLine();
        $this->table(['Champ', 'Valeur'], [
            ['ID',            $data['id']],
            ['Email',         $data['email']],
            ['Nom',           $data['name']],
            ['Email vérifié', $verified],
            ['Compte actif',  $active],
            ['Créé le',       $data['created_at'] ?? '—'],
        ]);

        $this->line("  <fg=gray>Rôles :</>  {$roles}");
        $this->newLine();
        $this->line("  <fg=gray>Organisations :</>  {$orgs}");
        $this->newLine();

        if ($data['permissions'] !== []) {
            $this->line('  <fg=gray>Permissions (' . count($data['permissions']) . ') :</>');
            foreach (array_chunk($data['permissions'], 3) as $chunk) {
                $this->line('    ' . implode('   ', $chunk));
            }
        }
    }

    private function actionPermissionsMatrix(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🔒  Matrice rôles × permissions</>');
        $this->newLine();

        $data = $this->debugService->permissionsMatrix();

        if (! empty($data['error'])) {
            $this->error('  Erreur : ' . $data['error']);

            return true;
        }

        if ($data['roles'] === []) {
            $this->warn('  Aucun rôle trouvé en base.');

            return true;
        }

        $roles  = $data['roles'];
        $perms  = $data['permissions'];
        $header = array_merge(['Permission'], $roles);
        $rows   = [];

        foreach ($perms as $perm) {
            $row = [$perm];
            foreach ($roles as $role) {
                $row[] = in_array($perm, $data['matrix'][$role] ?? [], true)
                    ? '<fg=green>✓</>'
                    : '<fg=gray>·</>';
            }
            $rows[] = $row;
        }

        $this->table($header, $rows);

        return true;
    }

    private function actionGenerateToken(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🔑  Génération de token Sanctum</>');
        $this->newLine();

        $email = $this->ask('  Email de l\'utilisateur');

        if (empty($email)) {
            $this->warn('  Email vide, opération annulée.');

            return true;
        }

        $result = $this->debugService->generateToken($email);

        if ($result === null) {
            $this->error("  Utilisateur introuvable : {$email}");

            return true;
        }

        $this->newLine();
        $this->line("  <fg=gray>Utilisateur :</> {$result['email']}");
        $this->line("  <fg=gray>Nom du token :</> {$result['token_name']}");
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>Token (à utiliser dans Authorization: Bearer ...) :</>');
        $this->line('  ' . $result['token']);
        $this->newLine();
        $this->warn('  Ce token est persisté en base. Pensez à le supprimer après utilisation.');

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions — Maintenance
    // ─────────────────────────────────────────────────────────────────────────

    private function actionRepairPermissions(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🔧  Réparation rôles / permissions</>');
        $this->newLine();

        $result = $this->maintenanceService->repairPermissions($this);

        $this->newLine();
        $this->info("  ✓  Seeder {$result['seeder']} exécuté, caches vidés.");

        return true;
    }

    private function actionClearCaches(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>🧹  Nettoyage des caches Laravel</>');
        $this->newLine();

        $this->maintenanceService->clearCaches($this);

        $this->newLine();
        $this->info('  ✓  Tous les caches ont été vidés.');

        return true;
    }

    private function actionValidateDemoEmails(): bool
    {
        $this->newLine();
        $this->line('  <options=bold>📧  Validation des emails fictifs</>');
        $this->newLine();

        $count = $this->maintenanceService->validateDemoEmails();

        if ($count === 0) {
            $this->info('  ✓  Tous les emails fictifs sont déjà vérifiés.');
        } else {
            $this->info("  ✓  {$count} email(s) marqué(s) comme vérifié(s).");
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions — Base de données
    // ─────────────────────────────────────────────────────────────────────────

    private function actionResetDemo(): bool
    {
        $word = (string) config('dev_toolbox.reset_demo.confirmation_word', 'RESET');

        $this->newLine();
        $this->line('  <fg=red;options=bold>⚠  RESET COMPLET DE LA DÉMO</>');
        $this->line('  Cette action supprime TOUTES les données et re-seede depuis zéro.');
        $this->newLine();

        $confirm = $this->ask("  Tapez <fg=red;options=bold>{$word}</> pour confirmer (autre chose pour annuler)");

        if ($confirm !== $word) {
            $this->line('  <fg=gray>Opération annulée.</>');

            return true;
        }

        $this->newLine();
        $this->line('  <fg=yellow>Lancement du reset...</>');
        $this->newLine();

        $this->databaseService->resetDemo($this);

        $this->newLine();
        $this->info('  ✓  Reset terminé. La base est dans son état de démo initial.');

        return true;
    }
}
