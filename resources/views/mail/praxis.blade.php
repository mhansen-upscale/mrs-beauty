{{--
    Das Mailgeruest fuer Patientinnen -- mit der Praxis in Kopf und Fuss, nicht
    mit uns (App\Benachrichtigung\Mailmarke). Der Rumpf entspricht Laravels
    notifications::email; nur der Rahmen ist anders.
--}}
<x-mail::praxis-layout :titel="$marke->praxisname">
<x-slot:header>
<x-mail::header :url="$marke->buchungsseite ?? '#'">
@if ($marke->logo)
<img src="{{ $marke->logo }}" alt="{{ $marke->praxisname }}" style="max-height: 48px; max-width: 240px;">
@else
{{ $marke->praxisname }}
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

@isset($actionText)
<x-slot:subcopy>
<x-mail::subcopy>
Falls die Schaltfläche „{{ $actionText }}“ nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
{{ $marke->praxisname }}
@if ($marke->impressum || $marke->datenschutz)
<br>
@if ($marke->impressum)[Impressum]({{ $marke->impressum }})@endif
@if ($marke->impressum && $marke->datenschutz) · @endif
@if ($marke->datenschutz)[Datenschutz]({{ $marke->datenschutz }})@endif
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::praxis-layout>
