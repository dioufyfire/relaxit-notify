<script setup>
import { Head, useForm, usePage } from "@inertiajs/vue3";
const page = usePage();
const form = useForm({ email: "", password: "" });
const submit = () =>
    form.post(page.props.urls.login, {
        onFinish: () => form.reset("password"),
    });
</script>

<template>
    <Head title="Connexion" />
    <div class="login-shell">
        <section class="login-story">
            <div class="brand">
                <span class="brand-mark">r<span>•</span></span
                ><span>RelaxIT <b>Notify</b></span>
            </div>
            <div class="story-body">
                <span class="eyebrow">PLUS PROCHES. MIEUX CONNECTÉS.</span>
                <h1>Le bon message.<br />Le bon lien.</h1>
                <p>
                    Un espace commun pour accompagner vos clients et préparer
                    leurs communications.
                </p>
                <div class="story-art" aria-hidden="true">
                    <div class="orbit orbit-one"></div>
                    <div class="orbit orbit-two"></div>
                    <div class="message-card">
                        <span class="message-symbol">↗</span>
                        <div>
                            Chaque relation compte.<small>RelaxIT Notify</small>
                        </div>
                        <span class="status-dot"></span>
                    </div>
                </div>
            </div>
            <small>Une plateforme RelaxIT · Sénégal</small>
        </section>
        <main class="login-main">
            <div class="login-form">
                <span class="eyebrow">VOTRE ESPACE RELAXIT</span>
                <h2>Heureux de vous retrouver.</h2>
                <p class="muted">
                    Connectez-vous pour accéder à votre console.
                </p>
                <form @submit.prevent="submit">
                    <label for="email">Adresse email</label
                    ><input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required
                        autofocus
                        :aria-invalid="!!form.errors.email"
                        aria-describedby="email-error"
                        placeholder="vous@entreprise.sn"
                    />
                    <p id="email-error" class="error" v-if="form.errors.email">
                        {{ form.errors.email }}
                    </p>
                    <label for="password">Mot de passe</label
                    ><input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        :aria-invalid="!!form.errors.password"
                        aria-describedby="password-error"
                    />
                    <p
                        id="password-error"
                        class="error"
                        v-if="form.errors.password"
                    >
                        {{ form.errors.password }}
                    </p>
                    <button class="primary full" :disabled="form.processing">
                        {{ form.processing ? "Connexion…" : "Se connecter" }}
                        <span aria-hidden="true">→</span>
                    </button>
                </form>
                <p class="login-help">
                    Besoin d’un accès ?<br />Contactez votre administrateur
                    RelaxIT.
                </p>
            </div>
            <small class="muted"
                >RelaxIT Notify · Espace réservé aux utilisateurs
                autorisés</small
            >
        </main>
    </div>
</template>
