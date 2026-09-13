<?php

namespace App\Console\Commands;

use App\Services\MetaWhatsApp;
use Illuminate\Console\Command;

class WhatsAppStatus extends Command
{
    protected $signature = 'relaxit:whatsapp-status';

    protected $description = 'Vérifier la configuration du pilote WhatsApp sans afficher de secret ni envoyer de message';

    public function handle(MetaWhatsApp $provider): int
    {
        $this->info(config('meta_whatsapp.enabled') ? 'Pilote activé.' : 'Envois désactivés.');
        $invalid = $provider->invalidSettings();
        if ($invalid !== []) {
            $this->warn('Paramètres manquants ou invalides : '.implode(', ', $invalid));

            return self::FAILURE;
        }
        $this->info('Configuration locale complète. Ceci ne vérifie ni le token chez Meta, ni le réseau, ni la livraison.');

        return self::SUCCESS;
    }
}
