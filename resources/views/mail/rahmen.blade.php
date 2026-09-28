{{--
    Das Mailgeruest -- fuer Mails der Praxis wie der Plattform (WP-36, WP-37).
    Kopf, Fuss und Farbe traegt die Marke (App\Benachrichtigung\Mailmarke):
    bei einer Terminmail die Praxis, bei einer Mail an ein Konto der Betreiber.
    Der Rumpf entspricht Laravels notifications::email; nur der Rahmen ist
    anders.
--}}
<x-mail::rahmen-layout :titel="$marke->name">
<x-slot:header>
<x-mail::header :url="$marke->startseite ?? '#'">
@if ($marke->logo)
<img src="{{ $marke->logo }}" alt="{{ $marke->name }}" style="max-height: 48px; max-width: 240px;">
@else
{{ $marke->name }}
@endif
</x-mail::header>
</x-slot:header>

@if (! empty($greeting))
# {{ $greeting }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@endif

@if ($marke->signatur !== [])
@foreach ($marke->signatur as $zeile)
{{ \App\Benachrichtigung\Vorlagen\Textbaustein::maskiere($zeile) }}@if (! $loop->last)<br>@endif
@endforeach
@endif

@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
Falls die Schaltfläche „{{ $actionText }}“ nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
{{ $marke->name }}
@if ($marke->impressum || $marke->datenschutz)
<br>
@if ($marke->impressum)[Impressum]({{ $marke->impressum }})@endif
@if ($marke->impressum && $marke->datenschutz) · @endif
@if ($marke->datenschutz)[Datenschutz]({{ $marke->datenschutz }})@endif
@endif
@if ($marke->fussnote)
<br>
{{ \App\Benachrichtigung\Vorlagen\Textbaustein::maskiere($marke->fussnote) }}
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::rahmen-layout>
