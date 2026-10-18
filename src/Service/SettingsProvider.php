<?php

namespace App\Service;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

/** Seul accès aux réglages (AD-12) : aucune valeur métier écrite dans le code. */
final class SettingsProvider
{
    public const string WHATSAPP_PHONE = 'whatsapp_phone';
    /** Centimes ; vide = non renseigné. */
    public const string DEPOSIT_AMOUNT = 'deposit_amount';
    /** Centimes par km. */
    public const string DELIVERY_PRICE_PER_KM = 'delivery_price_per_km';
    public const string DELIVERY_MIN_CHAIRS = 'delivery_min_chairs';
    public const string DELIVERY_MAX_KM = 'delivery_max_km';
    public const string DEPOT_CITY = 'depot_city';
    /** « 1 » = devis en ligne ouvert ; sinon « Demander sur WhatsApp » à la place (story 1.13). */
    public const string QUOTE_ONLINE = 'quote_online';
    /** Coordonnées et réseaux (story 2.1) ; vide = non affiché. */
    public const string PHONE = 'phone';
    public const string ADDRESS = 'address';
    public const string SIRET = 'siret';
    /** Lien `mailto:` de la page Contact (story 2.3) ; vide = pas de lien. */
    public const string EMAIL = 'email';
    /** Pourcentage d'acompte demandé à la réservation (« 50 »), affiché sur Comment ça marche (story 2.4). */
    public const string DEPOSIT_PERCENT = 'deposit_percent';
    /** Réseau affiché → clé ; l'ordre est celui du pied de page. */
    public const array SOCIAL_NETWORKS = [
        'Instagram' => 'instagram_url',
        'TikTok' => 'tiktok_url',
        'Facebook' => 'facebook_url',
        'YouTube' => 'youtube_url',
        'Pinterest' => 'pinterest_url',
    ];
    /** Lien « Voir tous nos avis sur Google » du Bloc avis (story 2.8) ; vide = pas de lien. */
    public const string GOOGLE_REVIEWS_URL = 'google_reviews_url';
    /** « Depuis 2026 » de Nos engagements (story 2.10) ; vide = engagement non affiché. */
    public const string FOUNDED_YEAR = 'founded_year';
    /** Texte de l'encadré L'entreprise de l'accueil (texte mis en forme simple, story 2.10). */
    public const string COMPANY_TEXT = 'company_text';
    /** « 1 » = site ouvert aux moteurs de recherche ; sinon noindex partout et robots.txt fermé (story 2.2). */
    public const string SEARCH_ENGINES_OPEN = 'search_engines_open';
    /** Réglages → Devis (story 3.1) : délais en jours, N < V ; vide = non renseigné. */
    public const string QUOTE_REMINDER_DAYS = 'quote_reminder_days';
    public const string QUOTE_VALIDITY_DAYS = 'quote_validity_days';
    /** Adresse qui reçoit les nouvelles demandes de devis. */
    public const string NOTIFICATION_EMAIL = 'notification_email';
    /** Supplément par chaise rendue sans nettoyage, centimes (story 3.2) ; vide = option non proposée. */
    public const string CLEANING_FEE = 'cleaning_fee';
    /** Fenêtre de créneaux autour de l'événement (story 3.10) : jours avant pour le retrait, après pour le retour ; moins de jours sous un seuil de chaises. */
    public const string SLOT_WINDOW_DAYS = 'slot_window_days';
    public const string SLOT_WINDOW_SMALL_DAYS = 'slot_window_small_days';
    public const string SLOT_WINDOW_SMALL_CHAIRS = 'slot_window_small_chairs';
    /** Réglages → Livraison (story 3.1) : chaises par camion, adresse complète du dépôt (calcul de distance). */
    public const string TRUCK_CAPACITY = 'truck_capacity';
    public const string DEPOT_ADDRESS = 'depot_address';
    /** Réglages → Conditions (story 3.2) : textes repris sur l'Encadré Conditions et les devis. */
    public const string RETURN_TERMS = 'return_terms';
    public const string PICKUP_TERMS = 'pickup_terms';
    public const string FUEL_TERMS = 'fuel_terms';
    /** Réglages → Paiement (story 3.2). Coordonnées bancaires : jamais affichées avant l'Epic 4. */
    public const string BANK_HOLDER = 'bank_holder';
    public const string IBAN = 'iban';
    public const string BIC = 'bic';
    /** Moyens acceptés, séparés par des virgules (clés de PAYMENT_METHOD_LABELS). */
    public const string PAYMENT_METHODS = 'payment_methods';
    public const array PAYMENT_METHOD_LABELS = ['virement' => 'Virement', 'especes' => 'Espèces', 'wero' => 'Wero'];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(string $name): string
    {
        if (null === $this->values) {
            $this->values = [];
            foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
                $this->values[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->values[$name] ?? '';
    }

    public function quoteOnline(): bool
    {
        return '1' === $this->get(self::QUOTE_ONLINE);
    }

    public function searchEnginesOpen(): bool
    {
        return '1' === $this->get(self::SEARCH_ENGINES_OPEN);
    }

    /** Numéro au format international sans « + » pour wa.me (06 12 34 56 78 → 33612345678) ; vide si non renseigné. */
    public function whatsappNumber(): string
    {
        return self::international($this->get(self::WHATSAPP_PHONE));
    }

    /** Numéro affiché pour l'appel : le téléphone, sinon le numéro WhatsApp (même ligne avant la story 2.1). */
    public function phoneDisplay(): string
    {
        return trim($this->get(self::PHONE)) ?: trim($this->get(self::WHATSAPP_PHONE));
    }

    /** Pour `tel:+…` ; vide si aucun numéro. */
    public function phoneNumber(): string
    {
        return self::international($this->phoneDisplay());
    }

    /** @return array<string, string> réseaux renseignés uniquement (nom → lien) */
    public function socialLinks(): array
    {
        return array_filter(array_map(fn (string $key) => trim($this->get($key)), self::SOCIAL_NETWORKS));
    }

    private static function international(string $phone): string
    {
        // « +33… », « 0033… » ou « 06… » → 33…
        $digits = preg_replace(['/\D/', '/^00/'], '', $phone);

        return 10 === \strlen($digits) && str_starts_with($digits, '0') ? '33'.substr($digits, 1) : $digits;
    }
}
