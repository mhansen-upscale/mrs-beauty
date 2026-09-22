<?php

declare(strict_types=1);

namespace App\Compliance\Regeln;

use App\Enums\ComplianceCode;

/**
 * Werbung mit Dankschreiben und Empfehlungen
 * (Paragraf 11 Abs. 1 Nr. 11 HWG).
 *
 * Auch dann, wenn die Aussage echt ist. Das Verbot haengt nicht an der
 * Wahrheit, sondern an der Form.
 */
final class Dankschreiben extends Wortregel
{
    protected function muster(): array
    {
        return [
            'Erfahrungsbericht', 'Erfahrungsberichte',
            'Patientenstimme', 'Patientenstimmen',
            'Kundenstimme', 'Kundenstimmen',
            'Dankschreiben', 'Testimonial',
            'Das sagen unsere Patientinnen', 'Das sagen unsere Patienten',
            'empfohlen von', 'Bewertung unserer Patientinnen',
        ];
    }

    protected function code(): ComplianceCode
    {
        return ComplianceCode::Testimonial;
    }

    protected function vorschlag(): string
    {
        return 'Stellen Sie stattdessen die Praxis vor: wer behandelt, wie ein Termin abläuft, '
            .'wie die Räume aussehen. Das wirkt und ist zulässig.';
    }
}
