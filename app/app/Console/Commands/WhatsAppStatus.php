<?php

namespace App\Console\Commands;

use App\Services\MetaWhatsApp;
use Illuminate\Console\Command;

class WhatsAppStatus extends Command
{
    protected $signature = 'relaxit:whatsapp-status';

    protected $description = 'Vérifier la configuration WhatsApp et le compteur local sans afficher de secret ni envoyer de message';

    public function handle(MetaWhatsApp $provider): int
    {
        $this->info(config('meta_whatsapp.enabled') ? 'Envois activés.' : 'Envois désactivés.');
        $this->info('Mode : '.(config('meta_whatsapp.mode', 'pilot') === 'production' ? 'production' : 'pilote'));
        $this->info(config('meta_whatsapp.webhook_enabled') ? 'Webhooks activés.' : 'Webhooks désactivés.');
        $invalid = $provider->invalidSettings();
        if (config('meta_whatsapp.webhook_enabled')) {
            foreach (['app_secret', 'verify_token'] as $setting) {
                if (! is_string(config('meta_whatsapp.'.$setting)) || config('meta_whatsapp.'.$setting) === '') {
                    $invalid[] = $setting;
                }
            }
            if (! preg_match('/\A[0-9]{5,30}\z/', (string) config('meta_whatsapp.waba_id'))) {
                $invalid[] = 'waba_id';
            }
        }
        if ($invalid !== []) {
            $this->warn('Paramètres manquants ou invalides : '.implode(', ', $invalid));

            return self::FAILURE;
        }
        $this->info('Tentatives locales sur 24 h : '.$provider->attemptsInLast24Hours().' / '.config('meta_whatsapp.max_attempts_per_24h', 250));
        $this->info('Configuration locale complète. Ceci ne vérifie ni le token chez Meta, ni le réseau, ni la livraison.');

        return self::SUCCESS;
    }
}
