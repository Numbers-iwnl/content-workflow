<x-layouts.hub title="Aprovar">
    <section class="rise pt-6 pb-8">
        <p class="eyebrow text-verde">Aprovação de conteúdos</p>
        <h1 class="title-serif mt-3 text-[46px] text-prata-lt">Olá, <span class="prata-text italic">{{ auth()->user()->name }}</span>.</h1>

        @if ($queue->isEmpty())
            <p class="mt-4 text-[17px] leading-relaxed text-prata">Nada esperando a sua aprovação agora. Quando a Ana enviar conteúdos, eles aparecem aqui.</p>
        @else
            <p class="mt-4 text-[17px] leading-relaxed text-prata">
                {{ $queue->count() === 1 ? 'Tem 1 conteúdo esperando' : "Tem {$queue->count()} conteúdos esperando" }} a sua decisão.
                Leva menos de um minuto cada.
            </p>
            <a href="{{ route('review.show', $queue->first()) }}" class="btn-verde btn-lg mt-7 w-full">Começar a revisar</a>
        @endif
    </section>

    @if ($queue->isNotEmpty())
        <ul class="grid grid-cols-2 gap-3">
            @foreach ($queue as $content)
                <li class="rise" style="animation-delay: {{ min($loop->index * 40, 400) }}ms">
                    <a href="{{ route('review.show', $content) }}" class="card-dark block overflow-hidden transition hover:border-verde/30">
                        <x-poster :content="$content" dark />
                        <div class="p-3">
                            <div class="flex items-center gap-1.5">
                                <x-account-dot :account="$content->account" size="xs" />
                                <span class="truncate text-[11px] text-prata">{{ $content->account?->label() }}</span>
                            </div>
                            <p class="mt-1.5 line-clamp-2 text-sm leading-snug text-prata-lt">{{ $content->title }}</p>
                            @if ($content->status === \App\Enums\ContentStatus::Corrigido)
                                <span class="eyebrow mt-2 inline-block text-[9px] text-verde-lt">Corrigido</span>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($recent->isNotEmpty())
        <section class="mt-12">
            <p class="eyebrow mb-3 text-prata-dk">Você revisou recentemente</p>
            <ul class="divide-y divide-white/[0.06] overflow-hidden rounded-2xl border border-white/[0.06]">
                @foreach ($recent as $content)
                    @php($mine = $content->reviews->first())
                    <li>
                        <a href="{{ route('review.show', $content) }}" class="flex items-center justify-between gap-3 px-4 py-3.5 hover:bg-white/[0.03]">
                            <span class="truncate text-sm text-prata-lt/90">{{ $content->title }}</span>
                            <span @class([
                                'shrink-0 text-xs',
                                'text-emerald-300' => $mine?->decision === \App\Enums\ReviewDecision::Aprovado,
                                'text-amber-200' => $mine?->decision === \App\Enums\ReviewDecision::Ajuste,
                                'text-red-300' => $mine?->decision === \App\Enums\ReviewDecision::Reprovado,
                            ])>{{ $mine?->decision->label() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.hub>
