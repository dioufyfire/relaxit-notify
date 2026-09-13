<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
defineProps({
    notifications: Object,
    tenant: Object,
    filters: Object,
    indexUrl: String,
    whatsappPilotEnabled: Boolean,
});
const labels = {
    pending: "À traiter",
    scheduled: "Planifiée",
    queued: "Traitement en attente",
    awaiting_provider: "En attente du fournisseur",
    blocked: "Bloquée",
    sending: "Envoi en cours",
    submitted: "Acceptée par Meta",
    failed: "Refusée par Meta",
    delivery_unknown: "Résultat à vérifier",
};
const date = (value) =>
    value ? new Date(value).toLocaleString("fr-FR") : "Dès que possible";
</script>

<template>
    <Head title="Notifications" />
    <AppLayout>
        <div class="page-heading">
            <div>
                <span class="eyebrow">SUIVI DES DEMANDES</span>
                <h1>Vos notifications.</h1>
                <p class="muted">
                    {{ tenant.name }} · Horaires affichés selon votre
                    navigateur.
                </p>
            </div>
            <button
                class="secondary"
                @click="router.reload({ only: ['notifications'] })"
            >
                Actualiser ↻
            </button>
        </div>
        <div class="notice">
            {{
                whatsappPilotEnabled
                    ? "Pilote WhatsApp activé pour le client, le destinataire et le modèle configurés. « Acceptée par Meta » ne confirme pas la livraison sur le téléphone."
                    : "Les envois WhatsApp sont désactivés. Les demandes restent enregistrées et préparées."
            }}
        </div>
        <section class="panel">
            <div class="section-heading notification-filter">
                <h2>Historique</h2>
                <div>
                    <label for="status">Filtrer par état</label
                    ><select
                        id="status"
                        :value="filters.status"
                        @change="
                            router.get(
                                indexUrl,
                                $event.target.value
                                    ? { status: $event.target.value }
                                    : {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        <option value="">Tous les états</option>
                        <option
                            v-for="(label, value) in labels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
            </div>
            <div v-if="!notifications.data.length" class="notification-empty">
                <h3>
                    {{
                        filters.status
                            ? "Aucune demande dans cet état."
                            : "Votre première notification apparaîtra ici."
                    }}
                </h3>
                <p class="muted">
                    {{
                        filters.status
                            ? "Essayez un autre filtre."
                            : "Les demandes transmises par votre application seront rattachées à ce client."
                    }}
                </p>
            </div>
            <article
                v-for="notification in notifications.data"
                :key="notification.id"
                class="notification-row"
            >
                <div>
                    <span class="badge">{{
                        labels[notification.status] ?? notification.status
                    }}</span>
                    <h3>{{ notification.template }}</h3>
                    <p v-if="notification.error_code" class="muted">
                        Code de suivi : {{ notification.error_code }}
                    </p>
                    <p>
                        {{ notification.application }} · WhatsApp ·
                        {{ notification.recipient_masked }}
                    </p>
                    <small class="muted notification-reference"
                        >Référence : {{ notification.id }}</small
                    >
                </div>
                <div class="notification-dates">
                    <small class="muted"
                        >Enregistrée le
                        {{ date(notification.created_at) }}</small
                    >
                    <small>Prévue : {{ date(notification.schedule_at) }}</small>
                    <small v-if="notification.prepared_at" class="muted"
                        >Traitée le {{ date(notification.prepared_at) }}</small
                    >
                </div>
            </article>
            <nav
                v-if="notifications.last_page > 1"
                class="pagination"
                aria-label="Pagination des notifications"
            >
                <Link
                    v-if="notifications.prev_page_url"
                    :href="notifications.prev_page_url"
                    >← Précédent</Link
                >
                <span
                    >{{ notifications.current_page }} /
                    {{ notifications.last_page }}</span
                >
                <Link
                    v-if="notifications.next_page_url"
                    :href="notifications.next_page_url"
                    >Suivant →</Link
                >
            </nav>
        </section>
    </AppLayout>
</template>
