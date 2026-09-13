<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { Head, Link, router, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
const props = defineProps({
    tenant: Object,
    keys: Object,
    canManage: Boolean,
    storeUrl: String,
    checkUrl: String,
});
const page = usePage();
const name = ref("");
const application = ref("");
const expires = ref("");
const secret = ref("");
const busy = ref(false);
const errors = ref([]);
const notice = ref("");
const pending = ref(null);
const copied = ref(false);
const clearSecret = () => {
    secret.value = "";
    copied.value = false;
};
let removeNavigation;
onMounted(() => {
    window.addEventListener("pagehide", clearSecret);
    removeNavigation = router.on("before", (event) => {
        if (event.detail.visit.url.pathname !== window.location.pathname)
            clearSecret();
    });
});
onBeforeUnmount(() => {
    clearSecret();
    window.removeEventListener("pagehide", clearSecret);
    removeNavigation?.();
});
const date = (value) => (value ? new Date(value).toLocaleString("fr-FR") : "—");
async function send(url, method, data = {}) {
    if (busy.value) return;
    busy.value = true;
    errors.value = [];
    notice.value = "";
    clearSecret();
    try {
        const csrfCookie = document.cookie
            .split("; ")
            .find((item) => item.startsWith("XSRF-TOKEN="));
        const response = await fetch(url, {
            method,
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-XSRF-TOKEN": csrfCookie
                    ? decodeURIComponent(csrfCookie.slice(11))
                    : "",
            },
            body: JSON.stringify(data),
        });
        const result = await response.json();
        if (!response.ok) {
            errors.value = result.errors
                ? Object.values(result.errors).flat()
                : [
                      response.status === 419
                          ? "Votre session a expiré. Rechargez la page."
                          : result.message || "Action refusée.",
                  ];
            return;
        }
        secret.value = result.secret || "";
        notice.value = result.secret
            ? "Clé créée. Copiez le secret maintenant : il ne pourra plus être affiché."
            : "La clé a été révoquée.";
        name.value = "";
        application.value = "";
        expires.value = "";
        pending.value = null;
        router.reload({ only: ["keys"] });
    } catch {
        errors.value = [
            "Le résultat de l’opération est incertain. Rechargez la liste avant de réessayer. Si une clé a été créée sans secret affiché, révoquez-la puis créez-en une nouvelle.",
        ];
    } finally {
        busy.value = false;
    }
}
async function copy() {
    try {
        await navigator.clipboard.writeText(secret.value);
        copied.value = true;
    } catch {
        errors.value = ["Sélectionnez et copiez manuellement le secret."];
    }
}
</script>

<template>
    <Head title="Clés API" />
    <AppLayout>
        <Link class="back-link" :href="page.props.urls.tenants">← Clients</Link>
        <div class="page-heading">
            <div>
                <span class="eyebrow">CONNEXIONS APPLICATIVES</span>
                <h1>Les clés de {{ tenant.name }}.</h1>
                <p class="muted">
                    Une clé par application. Des accès révocables à tout moment.
                </p>
            </div>
            <span class="badge">{{
                canManage ? "Gestion autorisée" : "Consultation uniquement"
            }}</span>
        </div>
        <div v-if="errors.length" class="error panel" role="alert">
            <p v-for="error in errors" :key="error">{{ error }}</p>
        </div>
        <div v-if="notice" class="notice" role="status">{{ notice }}</div>
        <section
            v-if="secret"
            class="panel secret-panel"
            aria-label="Votre nouvelle clé API"
        >
            <h2>Conservez votre clé maintenant.</h2>
            <p>
                Enregistrez-la dans la configuration sécurisée de votre
                application. Elle n’apparaîtra plus après avoir quitté cette
                page.
            </p>
            <label for="new-secret">Secret API</label
            ><input
                id="new-secret"
                :value="secret"
                readonly
                autocomplete="off"
                spellcheck="false"
            />
            <div class="key-actions">
                <button class="primary" type="button" @click="copy">
                    {{ copied ? "Copiée" : "Copier la clé" }}</button
                ><button class="secondary" type="button" @click="clearSecret">
                    J’ai conservé la clé
                </button>
            </div>
        </section>
        <section v-if="canManage" class="panel key-create">
            <h2>Connecter une application</h2>
            <form
                @submit.prevent="
                    send(storeUrl, 'POST', {
                        name,
                        application,
                        expires_at: expires || null,
                    })
                "
            >
                <div class="form-grid">
                    <div>
                        <label for="key-name">Nom de la connexion</label
                        ><input
                            id="key-name"
                            v-model="name"
                            required
                            maxlength="100"
                            placeholder="Dolibarr · Globale Santé"
                        />
                    </div>
                    <div>
                        <label for="application"
                            >Identifiant de l’application</label
                        ><input
                            id="application"
                            v-model="application"
                            required
                            maxlength="64"
                            pattern="[a-z][a-z0-9_-]*"
                            placeholder="dolibarr"
                        />
                    </div>
                    <div>
                        <label for="expires">Expiration (facultative)</label
                        ><input
                            id="expires"
                            v-model="expires"
                            type="date"
                        /><small class="muted"
                            >Expire au début de la date choisie, en UTC.</small
                        >
                    </div>
                </div>
                <div class="form-footer">
                    <span class="muted"
                        >Le secret n’est affiché qu’une fois.</span
                    ><button class="primary" :disabled="busy">
                        Créer une clé
                    </button>
                </div>
            </form>
        </section>
        <section class="panel">
            <h2>Clés enregistrées</h2>
            <p class="muted" v-if="!keys.data.length">
                Aucune application connectée pour le moment.
            </p>
            <article v-for="key in keys.data" :key="key.id" class="key-row">
                <div>
                    <h3>
                        {{ key.name }}
                        <span class="badge">{{ key.status }}</span>
                    </h3>
                    <p class="muted">Application : {{ key.application }}</p>
                    <small class="key-identifier"
                        >Référence publique : {{ key.public_id }}</small
                    >
                    <p class="muted key-dates">
                        Créée : {{ date(key.created_at) }} · Dernière
                        utilisation : {{ date(key.last_used_at)
                        }}<br />Expiration : {{ date(key.expires_at) }}
                    </p>
                </div>
                <div v-if="canManage" class="key-actions">
                    <button
                        v-if="key.status === 'Active'"
                        class="secondary"
                        :disabled="busy"
                        @click="pending = { key, type: 'rotate' }"
                    >
                        Renouveler</button
                    ><button
                        v-if="!key.revoked_at"
                        class="secondary danger"
                        :disabled="busy"
                        @click="pending = { key, type: 'revoke' }"
                    >
                        Révoquer
                    </button>
                </div>
            </article>
            <nav
                v-if="keys.last_page > 1"
                class="pagination"
                aria-label="Pagination des clés"
            >
                <Link v-if="keys.prev_page_url" :href="keys.prev_page_url"
                    >← Précédent</Link
                ><span>{{ keys.current_page }} / {{ keys.last_page }}</span
                ><Link v-if="keys.next_page_url" :href="keys.next_page_url"
                    >Suivant →</Link
                >
            </nav>
        </section>
        <section class="panel api-help">
            <h3>Vérifier la connexion</h3>
            <p class="muted">
                Votre application peut appeler cette adresse avec l’en-tête
                <code>Authorization: Bearer VOTRE_CLE</code>. La réponse indique
                le client associé à la clé. Aucun message n’est envoyé.
            </p>
            <code>GET {{ checkUrl }}</code>
        </section>
        <div v-if="pending" class="modal-backdrop">
            <section
                class="panel confirm-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-title"
            >
                <h2 id="confirm-title">
                    {{
                        pending.type === "rotate"
                            ? "Renouveler cette clé ?"
                            : "Révoquer cette clé ?"
                    }}
                </h2>
                <p>
                    {{ pending.key.name }} : l’ancienne clé cessera
                    immédiatement de fonctionner.
                    {{
                        pending.type === "rotate"
                            ? "Vous devrez enregistrer la nouvelle clé dans votre application. La date d’expiration restera identique."
                            : "Les applications qui l’utilisent perdront leur accès."
                    }}
                </p>
                <div class="key-actions">
                    <button
                        class="secondary"
                        :disabled="busy"
                        @click="pending = null"
                    >
                        Annuler</button
                    ><button
                        class="primary"
                        :disabled="busy"
                        @click="
                            send(
                                pending.type === 'rotate'
                                    ? pending.key.rotateUrl
                                    : pending.key.revokeUrl,
                                pending.type === 'rotate' ? 'POST' : 'DELETE',
                            )
                        "
                    >
                        Confirmer
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
