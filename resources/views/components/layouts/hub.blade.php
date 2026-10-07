@props(['title' => null, 'back' => null])
{{-- Hub de aprovação: preto, prata e verde (identidade da marca). --}}
<x-layouts.base :title="$title" theme="#0E100F" body-class="bg-carbon text-prata-lt">
    <div class="pointer-events-none fixed inset-x-0 top-0 h-72 bg-[radial-gradient(60%_100%_at_50%_0%,rgba(94,143,114,0.14),transparent)]"></div>

    <header class="relative z-30 mx-auto flex max-w-xl items-center justify-between px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-3">
        @if ($back)
            <a href="{{ $back }}" class="-ml-2 flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm text-prata hover:text-prata-lt">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.8 4.2a1 1 0 0 1 0 1.4L8.4 10l4.4 4.4a1 1 0 0 1-1.4 1.4l-5.1-5.1a1 1 0 0 1 0-1.4l5.1-5.1a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                Lista
            </a>
        @else
            <span class="flex items-baseline gap-2">
                <span class="title-serif text-[22px] text-prata-lt">Conteúdos</span>
                <span class="eyebrow text-[9px] text-verde">EC</span>
            </span>
        @endif

        <div class="flex items-center gap-1">
            {{ $actions ?? '' }}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg px-2 py-1.5 text-xs text-prata-dk hover:text-prata-lt">Sair</button>
            </form>
        </div>
    </header>

    <main class="relative z-10 mx-auto max-w-xl px-4 pb-40">
        @if ($errors->any())
            <div class="rise mb-4 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        {{ $slot }}
    </main>
</x-layouts.base>
