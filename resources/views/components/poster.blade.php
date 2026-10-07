@props(['content', 'dark' => false])
{{-- Capa do conteúdo nas listas (proporção do feed do Instagram, 4:5). --}}
@php
    $files = $content->files->where('state', \App\Enums\DriveFileState::Imported);
    $first = $files->first();
    $poster = $first?->posterUrl();
@endphp
<div {{ $attributes->class(['relative aspect-[4/5] overflow-hidden', $dark ? 'bg-carbon-3' : 'bg-paper-2']) }}>
    @if ($poster)
        <img src="{{ $poster }}" alt="" loading="lazy" class="absolute inset-0 size-full object-cover">
    @else
        <div @class(['absolute inset-0 flex flex-col items-center justify-center gap-2', $dark ? 'text-prata-dk' : 'text-mist'])>
            @if ($first?->isVideo())
                <svg class="size-9" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l10.6-6.86a1 1 0 0 0 0-1.7L9.52 4.3A1 1 0 0 0 8 5.14Z"/></svg>
            @else
                <svg class="size-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>
            @endif
            <span class="eyebrow text-[9px]">gerando prévia</span>
        </div>
    @endif

    <div class="absolute inset-x-0 top-0 flex items-start justify-between p-2.5">
        @if ($content->type)
            <span class="flex items-center gap-1 rounded-full bg-black/55 px-2 py-1 text-[10px] font-semibold tracking-wide text-white backdrop-blur-sm">
                @switch($content->type)
                    @case(\App\Enums\ContentType::Reel)
                        <svg class="size-3" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l10.6-6.86a1 1 0 0 0 0-1.7L9.52 4.3A1 1 0 0 0 8 5.14Z"/></svg>
                        @break
                    @case(\App\Enums\ContentType::Carrossel)
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="7" y="3" width="14" height="14" rx="2"/><path d="M3 7v12a2 2 0 0 0 2 2h12"/></svg>
                        @break
                    @default
                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                @endswitch
                {{ $content->type->label() }}
            </span>
        @else
            <span></span>
        @endif
        @if ($files->count() > 1)
            <span class="rounded-full bg-black/55 px-2 py-1 text-[10px] font-semibold text-white backdrop-blur-sm">1/{{ $files->count() }}</span>
        @endif
    </div>
    {{ $slot }}
</div>
