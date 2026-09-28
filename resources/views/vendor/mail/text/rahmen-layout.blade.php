{{--
    Der Textteil. Die Maskierung aus Textbaustein::maskiere() steht hier nicht
    im Weg: ein Backslash vor einem Satzzeichen ist Markdown, kein Text.
--}}
@php
    $ohneMaske = static fn (string $text): string => (string) preg_replace('/\\\\([\\\\`*_\[\]#!|~+.-])/u', '$1', $text);
@endphp
{!! $ohneMaske(strip_tags($header ?? '')) !!}

{!! $ohneMaske(strip_tags($slot)) !!}
@isset($subcopy)

{!! $ohneMaske(strip_tags($subcopy)) !!}
@endisset

{!! $ohneMaske(strip_tags($footer ?? '')) !!}
