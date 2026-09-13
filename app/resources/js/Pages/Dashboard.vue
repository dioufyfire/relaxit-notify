<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "../Layouts/AppLayout.vue";
defineProps({
    tenantCount: Number,
    notificationCounts: Object,
    whatsappPilotEnabled: Boolean,
});
const page = usePage();
</script>

<template>
    <Head title="Tableau de bord" />
    <AppLayout>
        <div class="page-heading">
            <div>
                <span class="eyebrow">VUE D’ENSEMBLE</span>
                <h1>Votre espace, en un regard.</h1>
                <p class="muted">Bienvenue, {{ page.props.auth.user.name }}.</p>
            </div>
            <Link class="primary" :href="page.props.urls.tenants"
                >Voir les clients <span aria-hidden="true">↗</span></Link
            >
        </div>
        <section class="hero-panel">
            <div>
                <span class="eyebrow">ESPACE DE TRAVAIL</span>
                <h2>
                    {{
                        page.props.currentTenant?.name ??
                        "Choisissez votre client."
                    }}
                </h2>
                <p>
                    {{
                        page.props.currentTenant
                            ? "Votre contexte de travail est actif. Retrouvez les coordonnées de ce client dans son espace."
                            : "Sélectionnez un client pour retrouver son contexte de travail et les accès qui vous sont attribués."
                    }}
                </p>
                <Link :href="page.props.urls.tenants" class="light-button"
                    >{{
                        page.props.currentTenant
                            ? "Changer de client"
                            : "Sélectionner un client"
                    }}
                    →</Link
                >
            </div>
            <div class="hero-glyph" aria-hidden="true">↗</div>
        </section>
        <div class="metric-grid">
            <section class="panel">
                <span class="muted">Clients accessibles</span
                ><strong class="metric">{{ tenantCount }}</strong>
                <p>Selon vos autorisations</p>
            </section>
            <section class="panel">
                <span class="muted">Votre rôle</span
                ><strong class="role-value">{{
                    page.props.auth.user.role
                }}</strong>
                <p>
                    {{
                        page.props.currentTenant?.code ??
                        "Sélectionnez un client pour préciser vos accès"
                    }}
                </p>
            </section>
        </div>
        <section class="panel next-panel">
            <div class="next-icon" aria-hidden="true">↗</div>
            <div>
                <span class="eyebrow">VOS NOTIFICATIONS</span>
                <h3>
                    {{
                        notificationCounts
                            ? Object.values(notificationCounts).reduce(
                                  (total, value) => total + Number(value),
                                  0,
                              ) + " demande(s) enregistrée(s)"
                            : "Suivez vos demandes."
                    }}
                </h3>
                <p class="muted">
                    {{
                        notificationCounts
                            ? (notificationCounts.awaiting_provider ?? 0) +
                              " en attente du fournisseur."
                            : "Sélectionnez un client pour consulter ses notifications."
                    }}
                </p>
            </div>
            <Link
                v-if="page.props.urls.notifications"
                :href="page.props.urls.notifications"
                class="secondary"
                >Consulter →</Link
            >
        </section>
    </AppLayout>
</template>
