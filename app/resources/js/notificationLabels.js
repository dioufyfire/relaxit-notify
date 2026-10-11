export const notificationLabels = {
    pending: "À traiter", scheduled: "Planifiée", queued: "Traitement en attente",
    awaiting_provider: "En attente du fournisseur", blocked: "Bloquée",
    sending: "Envoi en cours", submitted: "Acceptée par Meta", failed: "Échec WhatsApp",
    sent: "Envoyée", delivered: "Livrée", read: "Lue", delivery_unknown: "Résultat à vérifier",
};
export function errorHint(code) {
    const hints = {
        meta_delivery_131049: "Meta a refusé la livraison au titre des restrictions marketing. Vérifier la catégorie du modèle avant un nouvel essai.",
        meta_http_401: "Vérifier le token Meta chargé par le worker.",
        meta_http_403: "Vérifier les autorisations du compte Meta sur ce numéro.",
        meta_http_400: "Comparer le modèle, sa langue, son image et ses paramètres avec la configuration Meta.",
        meta_http_429: "Meta limite les envois. Éviter les relances immédiates.",
        whatsapp_daily_limit_reached: "Plafond local atteint. Cette demande ne sera pas relancée automatiquement.",
        whatsapp_config_invalid: "La configuration WhatsApp est incomplète ou invalide.",
        whatsapp_template_mismatch: "Le modèle ou ses variables ne correspondent pas à la configuration autorisée.",
        outside_whatsapp_pilot: "Client non autorisé ou destinataire exclu en mode pilote.",
        whatsapp_application_not_allowed: "L’application de la clé API n’est pas autorisée pour ces envois.",
        tenant_inactive: "Les envois de ce client sont suspendus.",
        meta_connection_uncertain: "La réception est incertaine. Vérifier le téléphone et Meta avant tout nouvel envoi.",
        meta_worker_interrupted: "Le traitement a été interrompu. Vérifier la réception avant tout nouvel envoi.",
        meta_response_uncertain: "La réponse de Meta ne permet pas de confirmer le résultat. Vérifier avant tout nouvel envoi.",
    };
    return hints[code] ?? "Consulter le détail de la demande et le code de suivi avant toute relance.";
}
