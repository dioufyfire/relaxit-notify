<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
defineProps({ tenants: Object, canCreate: Boolean });
const page = usePage();
</script>

<template>
    <Head title="Clients" />
    <AppLayout>
        <div class="page-heading">
            <div>
                <span class="eyebrow">VOS RELATIONS</span>
                <h1>Les espaces clients.</h1>
                <p class="muted">
                    {{ tenants.total }} client(s) accessibles avec votre compte.
                </p>
            </div>
            <Link
                v-if="canCreate"
                :href="page.props.urls.tenantCreate"
                class="primary"
                >+ Nouveau client</Link
            >
        </div>
        <section v-if="!tenants.data.length" class="panel empty-state">
            <h2>Aucun client accessible pour le moment.</h2>
            <p class="muted">
                Votre administrateur peut vous attribuer un espace client.
            </p>
        </section>
        <div class="tenant-grid">
            <article
                v-for="tenant in tenants.data"
                :key="tenant.id"
                class="panel tenant-card"
            >
                <div class="card-top">
                    <span class="tenant-avatar">{{
                        tenant.name.charAt(0)
                    }}</span
                    ><span class="badge">{{
                        page.props.currentTenant?.id === tenant.id
                            ? "Sélectionné"
                            : "Actif"
                    }}</span>
                </div>
                <h2>
                    <Link :href="tenant.url">{{ tenant.name }}</Link>
                </h2>
                <span class="tenant-code">{{ tenant.code }}</span>
                <p class="muted">
                    {{ tenant.address || "Adresse à compléter" }}
                </p>
                <div class="card-actions">
                    <Link :href="tenant.url">Voir la fiche ↗</Link
                    ><Link
                        :href="tenant.selectUrl"
                        method="post"
                        as="button"
                        class="secondary"
                        >Sélectionner</Link
                    >
                </div>
            </article>
        </div>
        <nav
            class="pagination"
            aria-label="Pagination"
            v-if="tenants.last_page > 1"
        >
            <Link
                v-if="tenants.prev_page_url"
                :href="tenants.prev_page_url"
                class="secondary"
                >← Précédent</Link
            ><span
                >Page {{ tenants.current_page }} / {{ tenants.last_page }}</span
            ><Link
                v-if="tenants.next_page_url"
                :href="tenants.next_page_url"
                class="secondary"
                >Suivant →</Link
            >
        </nav>
    </AppLayout>
</template>
