<?php

namespace App\Console\Commands;

use App\Enums\PlatformRole;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class BootstrapRelaxit extends Command
{
    protected $signature = 'relaxit:bootstrap';

    protected $description = 'Créer le premier Super Admin RelaxIT avec saisie masquée du mot de passe';

    public function handle(): int
    {
        if (User::where('platform_role', PlatformRole::SuperAdmin)->exists()) {
            $this->info('Un Super Admin existe déjà. Aucun compte modifié.');

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Exécutez cette commande dans un terminal interactif. Aucun mot de passe par défaut.');

            return self::FAILURE;
        }

        $name = $this->ask('Nom', 'Admin RelaxIT');
        $email = mb_strtolower(trim((string) $this->ask('Adresse email')));
        $password = $this->secret('Mot de passe (12 caractères minimum)', false);
        $confirmation = $this->secret('Confirmez le mot de passe', false);
        $validator = Validator::make([
            'name' => $name, 'email' => $email, 'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('Le mot de passe ne doit pas dépasser 72 octets.');
                    }
                }],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $email, $password): void {
                (new DatabaseSeeder)->run();
                // Le tenant pilote sert de verrou commun à deux initialisations concurrentes.
                Tenant::where('code', 'GLOBALE_SANTE')->lockForUpdate()->firstOrFail();
                if (User::where('platform_role', PlatformRole::SuperAdmin)->exists()) {
                    throw new RuntimeException('Un Super Admin existe déjà. Aucun compte modifié.');
                }
                if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new RuntimeException('Cette adresse appartient déjà à un compte. Aucune promotion effectuée.');
                }
                $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->platform_role = PlatformRole::SuperAdmin;
                $user->save();
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Super Admin créé. Connectez-vous sur /login.');

        return self::SUCCESS;
    }
}
