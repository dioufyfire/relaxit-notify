<script setup>
import { Head, Link } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
defineProps({ events: Object, tenant: Object });
const labels = {
    "notification.sending": "Envoi WhatsApp commencé",
    "notification.submitted": "Demande acceptée par Meta",
    "notification.failed": "Demande refusée par Meta",
    "notification.delivery_unknown": "Résultat Meta à vérifier",
    "notification.accepted": "Notification enregistrée",
    "notification.awaiting_provider":
        "Notification prête, fournisseur à connecter",
    "notification.blocked": "Notification bloquée",
    "auth.login": "Connexion réussie",
    "auth.failed": "Échec de connexion",
    "auth.logout": "Déconnexion",
    "platform.admin_created": "Premier Super Admin créé",
    "tenant.created": "Client créé",
    "tenant.updated": "Coordonnées modifiées",
    "tenant.selected": "Client sélectionné",
    "api_key.created": "Clé API créée",
    "api_key.rotated": "Clé API renouvelée",
    "api_key.revoked": "Clé API révoquée",
};
</script>

<template>
    <Head title="Journal d’audit" />
    <AppLayout>
        <div class="page-heading">
            <div>
                <span class="eyebrow">TRAÇABILITÉ</span>
                <h1>Le journal des actions.</h1>
                <p class="muted">
                    {{ tenant?.name ?? "Ensemble de la plateforme" }} · Horaires
                    affichés selon votre navigateur.
                </p>
            </div>
            <span class="badge">Consultation uniquement</span>
        </div>
        <section class="panel">
            <p v-if="!events.data.length" class="muted">
                Les prochaines actions apparaîtront ici. Les événements
                antérieurs à l’activation du journal ne sont pas reconstitués.
            </p>
            <article
                v-for="event in events.data"
                :key="event.id"
                class="audit-row"
            >
                <time>{{
                    new Date(event.created_at).toLocaleString("fr-FR")
                }}</time>
                <div>
                    <h3>{{ labels[event.action] ?? event.action }}</h3>
                    <p>{{ event.actor }} · {{ event.tenant }}</p>
                    <small class="muted" v-if="event.metadata?.application"
                        >Application : {{ event.metadata.application }} ·
                        <template v-if="event.metadata.key_id"
                            >Référence de clé :
                            {{ event.metadata.key_id }}</template
                        ></small
                    ><small class="muted" v-if="event.metadata?.notification_id"
                        >Notification :
                        {{ event.metadata.notification_id }}</small
                    ><small class="muted" v-if="event.metadata?.fields?.length"
                        >Champs : {{ event.metadata.fields.join(", ") }}</small
                    ><small class="muted" v-if="event.ip_address"
                        >Adresse IP : {{ event.ip_address }}</small
                    >
                </div>
            </article>
            <nav
                v-if="events.last_page > 1"
                class="pagination"
                aria-label="Pagination du journal"
            >
                <Link v-if="events.prev_page_url" :href="events.prev_page_url"
                    >← Précédent</Link
                ><span>{{ events.current_page }} / {{ events.last_page }}</span
                ><Link v-if="events.next_page_url" :href="events.next_page_url"
                    >Suivant →</Link
                >
            </nav>
        </section>
    </AppLayout>
</template>
