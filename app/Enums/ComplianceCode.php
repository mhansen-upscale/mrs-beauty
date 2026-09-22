<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Der Startregelsatz aus `specs/WP-30-hwg-compliance.md`.
 *
 * **Jeder Code traegt seine Fundstelle.** Ein Befund ohne Paragraf ist ein
 * Vorwurf; einer mit Fundstelle ist eine Auskunft, die jemand nachlesen kann.
 */
enum ComplianceCode: string
{
    case BeforeAfter = 'before_after';

    case MissingRiskNotice = 'missing_risk_notice';

    case HealingPromise = 'healing_promise';

    case FearAdvertising = 'fear_advertising';

    case Testimonial = 'testimonial';

    case RiskFreeClaims = 'risk_free_claims';

    case Superlatives = 'superlatives';

    case BrandViolation = 'brand_violation';

    public function label(): string
    {
        return match ($this) {
            self::BeforeAfter => 'Vorher-Nachher-Darstellung',
            self::MissingRiskNotice => 'Pflichthinweis auf Risiken fehlt',
            self::HealingPromise => 'Erfolgsversprechen',
            self::FearAdvertising => 'Angst erzeugende Darstellung',
            self::Testimonial => 'Werbung mit Empfehlungen',
            self::RiskFreeClaims => 'Aussage über Risikofreiheit',
            self::Superlatives => 'Unbelegte Spitzenstellung',
            self::BrandViolation => 'Verstoß gegen den Brand Guide',
        };
    }

    public function fundstelle(): string
    {
        return match ($this) {
            self::BeforeAfter => '§ 11 Abs. 1 S. 3 Nr. 1 HWG, BGH I ZR 170/24 vom 31.07.2025',
            self::MissingRiskNotice => '§ 11 Abs. 1 S. 3 Nr. 2 HWG',
            self::HealingPromise => '§ 3 HWG',
            self::FearAdvertising => '§ 11 Abs. 1 Nr. 7 HWG',
            self::Testimonial => '§ 11 Abs. 1 Nr. 11 HWG',
            self::RiskFreeClaims => '§ 3 HWG',
            self::Superlatives => 'UWG',
            self::BrandViolation => 'Brand Guide der Praxis',
        };
    }

    /**
     * Rot heisst: so nicht.
     *
     * Gelb heisst: jemand muss hinsehen. **Nicht gruen**, denn ein Befund,
     * den die Maschine nicht entscheiden kann, ist keiner, den sie
     * durchwinken darf (Regel 6).
     */
    public function ampel(): Ampel
    {
        return match ($this) {
            // Seit dem BGH-Urteil vom 31.07.2025 auch bei minimalinvasiven
            // Eingriffen. Verstoesse koennen mit bis zu 50.000 Euro geahndet
            // werden.
            self::BeforeAfter => Ampel::Rot,
            self::HealingPromise, self::RiskFreeClaims, self::Testimonial => Ampel::Rot,

            self::MissingRiskNotice, self::FearAdvertising, self::Superlatives => Ampel::Gelb,

            // Eine Geschmacksfrage der Praxis, kein Rechtsverstoss.
            self::BrandViolation => Ampel::Gelb,
        };
    }
}
