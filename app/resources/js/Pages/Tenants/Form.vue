<script setup>
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import AppLayout from "../../Layouts/AppLayout.vue";
const props = defineProps({
    tenant: Object,
    canUpdate: Boolean,
    saveUrl: String,
    selectUrl: String,
    apiKeysUrl: String,
    auditUrl: String,
});
const page = usePage();
const form = useForm({
    ...(props.tenant ? {} : { code: "" }),
    name: props.tenant?.name ?? "",
    address: props.tenant?.address ?? "",
    phone: props.tenant?.phone ?? "",
    email: props.tenant?.email ?? "",
});
const submit = () =>
    props.tenant ? form.patch(props.saveUrl) : form.post(props.saveUrl);
const fields = [
    { key: "name", label: "Nom du client", type: "text" },
    { key: "address", label: "Adresse", type: "text" },
    { key: "phone", label: "Téléphone", type: "tel" },
    { key: "email", label: "Email de contact", type: "email" },
];
</script>

<template>
    <Head :title="tenant?.name ?? 'Nouveau client'" />
    <AppLayout>
        <Link class="back-link" :href="page.props.urls.tenants"
            >← Tous les clients</Link
        >
        <div class="page-heading">
            <div>
                <span class="eyebrow">FICHE CLIENT</span>
                <h1>{{ tenant?.name ?? "Un nouvel espace client." }}</h1>
                <p class="muted">
                    {{
                        tenant?.code ??
                        "Renseignez les coordonnées de votre client."
                    }}
                </p>
            </div>
            <Link
                v-if="tenant"
                :href="selectUrl"
                method="post"
                as="button"
                class="primary"
                >Travailler avec ce client →</Link
            >
        </div>
        <div class="key-actions section-links">
            <Link v-if="apiKeysUrl" :href="apiKeysUrl" class="secondary"
                >Clés API →</Link
            >
            <Link v-if="auditUrl" :href="auditUrl" class="secondary"
                >Journal d’audit →</Link
            >
        </div>
        <section class="panel form-panel">
            <div class="section-heading">
                <h2>Informations générales</h2>
                <span class="badge">{{
                    canUpdate
                        ? "Modification autorisée"
                        : "Consultation uniquement"
                }}</span>
            </div>
            <form @submit.prevent="submit">
                <template v-if="!tenant"
                    ><label for="code">Identifiant du client</label
                    ><input
                        id="code"
                        v-model="form.code"
                        required
                        maxlength="64"
                        pattern="[A-Z][A-Z0-9_]*"
                        placeholder="EXEMPLE_CLIENT"
                        aria-describedby="code-help"
                    /><small id="code-help" class="muted"
                        >Lettres majuscules, chiffres et underscores. Cet
                        identifiant ne sera plus modifiable.</small
                    ></template
                >
                <div class="form-grid">
                    <div v-for="field in fields" :key="field.key">
                        <label :for="field.key"
                            >{{ field.label
                            }}{{ field.key === "name" ? " *" : "" }}</label
                        ><input
                            :id="field.key"
                            v-model="form[field.key]"
                            :type="field.type"
                            :required="field.key === 'name'"
                            :maxlength="field.key === 'phone' ? 40 : 255"
                            :readonly="!canUpdate"
                            :aria-invalid="!!form.errors[field.key]"
                        />
                    </div>
                </div>
                <ul
                    v-if="Object.keys(form.errors).length"
                    class="error"
                    role="alert"
                >
                    <li v-for="(error, key) in form.errors" :key="key">
                        {{ error }}
                    </li>
                </ul>
                <div class="form-footer" v-if="canUpdate">
                    <span class="muted">* Champ obligatoire</span
                    ><button class="primary" :disabled="form.processing">
                        {{
                            form.processing
                                ? "Enregistrement…"
                                : tenant
                                  ? "Enregistrer les modifications"
                                  : "Créer le client"
                        }}
                    </button>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
