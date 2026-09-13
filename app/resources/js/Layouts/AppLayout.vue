<script setup>
import { Link, usePage } from "@inertiajs/vue3";
const page = usePage();
</script>

<template>
    <div class="app-shell">
        <a class="skip-link" href="#main">Aller au contenu</a>
        <aside class="sidebar">
            <Link :href="page.props.urls.dashboard" class="brand"
                ><span class="brand-mark">r<span>•</span></span
                ><span
                    >RelaxIT <b>Notify</b><small>ESPACE DE GESTION</small></span
                ></Link
            >
            <div class="sidebar-label">VOTRE ESPACE</div>
            <nav aria-label="Navigation principale">
                <Link
                    :href="page.props.urls.dashboard"
                    :class="{ active: page.url.startsWith('/dashboard') }"
                    ><span aria-hidden="true">◫</span> Tableau de bord</Link
                >
                <Link
                    :href="page.props.urls.tenants"
                    :class="{ active: page.url.startsWith('/tenants') }"
                    ><span aria-hidden="true">▦</span> Clients</Link
                >
            </nav>
            <div class="sidebar-note">
                <span class="status-dot"></span> Votre plateforme grandit
                <p>Retrouvez ici vos clients et leurs espaces de travail.</p>
            </div>
            <div class="profile">
                <span class="avatar">{{
                    page.props.auth.user.name.charAt(0)
                }}</span>
                <div>
                    <strong>{{ page.props.auth.user.name }}</strong
                    ><small>{{ page.props.auth.user.role }}</small>
                </div>
            </div>
            <Link
                :href="page.props.urls.logout"
                method="post"
                as="button"
                class="logout"
                >Se déconnecter ↗</Link
            >
        </aside>
        <div class="workspace">
            <header class="topbar">
                <span>RelaxIT / <strong>Console</strong></span
                ><span class="context-pill">{{
                    page.props.currentTenant?.name ?? "Aucun client sélectionné"
                }}</span>
            </header>
            <main id="main">
                <div
                    v-if="page.props.flash.success"
                    class="notice"
                    role="status"
                >
                    {{ page.props.flash.success }}
                </div>
                <slot />
            </main>
            <footer>
                RelaxIT Notify <span>Conçu pour garder le lien.</span>
            </footer>
        </div>
    </div>
</template>
