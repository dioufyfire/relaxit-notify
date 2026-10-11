<script setup>
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AppLayout from "../Layouts/AppLayout.vue";
import { notificationLabels, errorHint } from "../notificationLabels";
defineProps({ tenantCount: Number, operations: Object, quota: Object, whatsappPilotEnabled: Boolean, whatsappMode: String, refreshedAt: String });
const page = usePage();
const date = (value) => new Date(value).toLocaleString("fr-FR");
const statuses = ["failed", "blocked", "delivery_unknown", "awaiting_provider"];
</script>
<template>
    <Head title="Suivi quotidien" />
    <AppLayout>
        <div class="page-heading">
            <div>
                <span class="eyebrow">SUIVI QUOTIDIEN</span>
                <h1>{{ page.props.currentTenant?.name ?? "Votre tableau de bord" }}</h1>
                <p class="muted">Mis à jour le {{ date(refreshedAt) }} · Horaires de votre navigateur</p>
            </div>
            <button class="secondary" @click="router.reload()">Actualiser ↻</button>
        </div>
        <div class="notice">
            {{ whatsappPilotEnabled ? (whatsappMode === 'production' ? 'Envois WhatsApp activés · mode production.' : 'Envois WhatsApp activés · mode pilote.') : 'Les envois WhatsApp sont désactivés.' }}
            Cette configuration ne confirme pas la disponibilité de Meta ou du cron.
        </div>
        <section v-if="!operations" class="panel notification-empty">
            <h2>Sélectionnez un client pour consulter ses demandes.</h2>
            <p class="muted">{{ tenantCount }} client(s) accessible(s) selon vos autorisations.</p>
            <Link class="primary" :href="page.props.urls.tenants">Choisir un client →</Link>
        </section>
        <template v-if="operations">
            <div class="metric-grid operations-metrics">
                <section class="panel"><span class="muted">Demandes reçues · 24 h</span><strong class="metric">{{ operations.received24h }}</strong><p>Depuis 24 heures glissantes</p></section>
                <section class="panel"><span class="muted">Déjà livrées ou lues</span><strong class="metric">{{ operations.delivered24h }}</strong><p>Parmi les demandes reçues sur ces 24 h</p></section>
                <section class="panel"><span class="muted">Tentatives de ce client · 24 h</span><strong class="metric">{{ operations.attempts24h }}</strong><p>Échecs et résultats incertains inclus</p></section>
            </div>
            <section class="panel operations-section">
                <div class="section-heading"><div><span class="eyebrow">TOUTES DATES</span><h2>Demandes à examiner</h2></div><Link :href="page.props.urls.notifications" class="secondary">Tout l’historique →</Link></div>
                <div class="operations-statuses">
                    <Link v-for="status in statuses" :key="status" :href="operations.links[status]" class="operations-status">
                        <strong>{{ operations.counts[status] ?? 0 }}</strong><span>{{ notificationLabels[status] }} →</span>
                    </Link>
                </div>
                <p class="muted">Ces états ne sont pas des alertes acquittées : ils restent affichés tant que la demande conserve cet état.</p>
                <p v-if="operations.overdue" class="operations-warning">{{ operations.overdue }} demande(s) échue(s) depuis plus d’une heure sans livraison confirmée. Vérifier le traitement et les retours Meta ; ce délai ne prouve pas un échec.</p>
                <p v-if="!operations.issues.length" class="notification-empty">Aucun échec, blocage ou résultat incertain enregistré pour ce client.</p>
                <article v-for="issue in operations.issues" :key="issue.id" class="notification-row">
                    <div><span class="badge">{{ notificationLabels[issue.status] }}</span><h3>{{ issue.template }}</h3><p>{{ errorHint(issue.error_code) }}</p><small class="muted notification-reference">{{ issue.id }} · {{ issue.error_code ?? 'Sans code de suivi' }} · {{ date(issue.updated_at) }}</small></div>
                    <Link class="secondary operations-detail" :href="issue.url">Voir la demande →</Link>
                </article>
                <p v-if="operations.issues.length" class="muted">Les dix dernières demandes en échec, bloquées ou à résultat incertain. Aucun renvoi automatique depuis cet écran.</p>
            </section>
            <section class="panel operations-section">
                <span class="eyebrow">RAPPELS NON TRANSMIS</span><h2>À consulter dans Dolimed Notif</h2>
                <p>Les rappels expirés, annulés ou encore planifiés dans Dolimed ne sont pas tous transmis à RelaxIT. Consultez le récapitulatif du module Dolimed Notif pour les contrôler.</p>
            </section>
        </template>
        <section v-if="quota" class="panel operations-section">
            <span class="eyebrow">RELAXIT · TOUTE L’INSTALLATION</span><h2>Plafond local sur 24 heures</h2>
            <template v-if="quota.limit !== null">
                <p class="quota-value"><strong>{{ quota.used }}</strong> / {{ quota.limit }} tentatives · {{ quota.remaining }} disponible(s)</p>
                <progress class="quota-progress" :value="Math.min(quota.used, quota.limit)" :max="quota.limit" aria-label="Consommation du plafond local"></progress>
                <p v-if="quota.remaining === 0" class="operations-warning">Plafond atteint. Les nouvelles demandes éligibles seront bloquées, sans relance automatique.</p>
            </template>
            <p v-else class="operations-warning">Plafond mal configuré : faire vérifier le paramètre par l’administrateur.</p>
            <p class="muted">Compteur partagé entre les clients de cette installation, échecs compris. Ce n’est pas le quota Meta et les envois depuis d’autres outils ne sont pas comptés. La capacité se libère au fil des 24 heures, pas à minuit.</p>
        </section>
    </AppLayout>
</template>
