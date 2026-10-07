@props(['content', 'dark' => false])
{{-- Prévia do post: vídeo, imagem única ou carrossel (deslizar para o lado). --}}
@php($files = $content->files->where('state', \App\Enums\DriveFileState::Imported)->values())

@if ($files->isEmpty())
    <div @class(['flex aspect-[4/5] items-center justify-center text-sm', $dark ? 'bg-carbon-3 text-prata' : 'bg-paper-2 text-slate'])>Sem arquivo</div>
@else
    <div {{ $attributes->class(['relative']) }} x-data="{ slide: 0 }">
        <div class="no-scrollbar flex snap-x snap-mandatory overflow-x-auto"
             @scroll.debounce.50ms="slide = Math.round($el.scrollLeft / $el.clientWidth)">
            @foreach ($files as $file)
                <div class="relative flex w-full shrink-0 snap-center items-center justify-center bg-black">
                    @if (! $file->hasFreshCache())
                        <div class="flex aspect-[4/5] w-full flex-col items-center justify-center gap-3 p-8 text-center text-sm text-prata">
                            @if ($file->posterUrl())
                                <img src="{{ $file->posterUrl() }}" alt="" class="absolute inset-0 size-full object-cover opacity-40">
                            @endif
                            <svg class="relative size-6 animate-spin text-verde" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity=".25" stroke-width="2.5"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="2.5"/></svg>
                            <p class="relative">Preparando a prévia…<br><span class="text-xs text-prata-dk">Recarregue a página em instantes.</span></p>
                            @if ($file->web_view_link)
                                <a href="{{ $file->web_view_link }}" target="_blank" rel="noopener" class="relative text-xs text-verde-lt underline underline-offset-4">Abrir no Google Drive</a>
                            @endif
                        </div>
                    @elseif ($file->isVideo())
                        <video src="{{ route('media.file', $file) }}" @if ($file->thumbnail_path) poster="{{ route('media.thumbnail', $file) }}" @endif
                               controls playsinline preload="metadata" class="max-h-[78dvh] w-full bg-black"></video>
                    @else
                        <img src="{{ route('media.file', $file) }}" alt="{{ $file->name }}" loading="lazy" class="max-h-[78dvh] w-full object-contain">
                    @endif
                </div>
            @endforeach
        </div>

        @if ($files->count() > 1)
            <span class="absolute top-3 right-3 rounded-full bg-black/60 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur" x-text="`${slide + 1}/{{ $files->count() }}`">1/{{ $files->count() }}</span>
            <div class="mt-3 flex justify-center gap-1.5">
                @foreach ($files as $file)
                    <span class="h-1.5 rounded-full transition-all duration-300"
                          :class="slide === {{ $loop->index }} ? 'w-5 {{ $dark ? 'bg-verde' : 'bg-grafite' }}' : 'w-1.5 {{ $dark ? 'bg-white/20' : 'bg-mist' }}'"></span>
                @endforeach
            </div>
        @endif
    </div>
@endif
