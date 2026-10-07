@php
    $quick = ['Trocar a música', 'Cortar um trecho', 'Ajustar a legenda', 'Mudar a capa', 'Texto na tela', 'Cor / edição'];
    $account = $content->account;
    $openSheet = old('decision') && old('decision') !== 'aprovado' ? old('decision') : null;
@endphp

<x-layouts.hub :title="$content->title" :back="route('review.index')">
    <x-slot:actions>
        @if ($position !== false && $queueSize > 1)
            <span class="mr-2 text-xs text-prata tabular-nums">{{ $position + 1 }} de {{ $queueSize }}</span>
        @endif
    </x-slot:actions>

    @if ($position !== false && $queueSize > 1)
        <div class="mb-4 flex gap-1">
            @for ($i = 0; $i < $queueSize; $i++)
                <span @class(['h-[3px] flex-1 rounded-full', 'bg-verde-lt' => $i <= $position, 'bg-white/10' => $i > $position])></span>
            @endfor
        </div>
    @endif

    {{-- Onde vai ser postado: bem visível --}}
    <div class="rise mb-4 flex items-center gap-4 rounded-[20px] border px-4 py-3.5"
         style="border-color: {{ $account?->color() ?? '#3a3f3c' }}66; background: linear-gradient(100deg, {{ $account?->color() ?? '#3a3f3c' }}33, transparent 70%)">
        <x-account-dot :account="$account" size="md" class="ring-2 ring-white/20" />
        <div class="min-w-0 flex-1">
            <p class="eyebrow text-[10px] text-prata">Vai ser postado em</p>
            <p class="title-serif truncate text-[28px] text-white">{{ $account?->label() ?? 'Área a definir' }}</p>
            @if ($content->collab_accounts)
                <p class="truncate text-xs text-prata">em collab com {{ collect($content->collab_accounts)->map(fn ($a) => \App\Enums\Account::from($a)->label())->join(' e ') }}</p>
            @endif
        </div>
        <div class="shrink-0 text-right">
            <p class="eyebrow text-[10px] text-prata">{{ $content->type?->label() }}</p>
            <p class="mt-1 text-xs text-prata-lt">{{ $content->cta?->label() }}</p>
        </div>
    </div>

    <div x-data="reviewSheet(@js($openSheet), @js(old('note', '')))">
        {{-- O post como vai aparecer --}}
        <article class="rise overflow-hidden rounded-[20px] border border-white/[0.07] bg-carbon-2">
            <x-media :content="$content" dark />

            <div class="space-y-3 p-4" x-data="{ open: false }">
                @if ($content->caption)
                    <p class="text-[15px] leading-relaxed whitespace-pre-line text-prata-lt/90" :class="! open && 'line-clamp-4'">{{ $content->caption }}</p>
                    @if (mb_strlen($content->caption) > 180 || substr_count($content->caption, "\n") > 3)
                        <button type="button" class="text-sm text-prata hover:text-prata-lt" @click="open = ! open" x-text="open ? 'mostrar menos' : '… mais'"></button>
                    @endif
                @else
                    <p class="text-sm text-prata-dk italic">Ainda sem legenda.</p>
                @endif
            </div>
        </article>

        <dl class="mt-4 grid grid-cols-2 gap-2 text-center">
            <div class="card-dark px-2 py-3"><dt class="eyebrow text-[9px] text-prata-dk">Projeto</dt><dd class="mt-1 truncate text-sm text-prata-lt">{{ $content->project ?? '—' }}</dd></div>
            <div class="card-dark px-2 py-3"><dt class="eyebrow text-[9px] text-prata-dk">Produção</dt><dd class="mt-1 truncate text-sm text-prata-lt">{{ $content->produced_by ?? '—' }}</dd></div>
        </dl>

        <h1 class="mt-5 text-xs text-prata-dk">{{ $content->title }}</h1>

        @if ($mine)
            <p class="card-dark mt-4 px-4 py-3 text-sm text-prata">
                Sua última decisão: <b class="text-prata-lt">{{ $mine->decision->short() }}</b>.
                @if ($canReview) Pode mudar se quiser. @endif
            </p>
        @endif

        @if ($content->reviews->isNotEmpty())
            <details class="mt-6" @if ($content->round > 1) open @endif>
                <summary class="eyebrow cursor-pointer list-none text-verde-lt">Opiniões e pedidos ({{ $content->reviews->count() }})</summary>
                <x-review-history :reviews="$content->reviews" dark class="mt-3" />
            </details>
        @endif

        @if (! $canReview)
            <p class="card-dark mt-6 p-4 text-center text-sm text-prata">
                Este conteúdo não está mais em aprovação ({{ mb_strtolower($content->status->label()) }}).
            </p>
        @else
            {{-- Decisão: fixa no rodapé, ao alcance do polegar --}}
            <div class="fixed inset-x-0 bottom-0 z-40 bg-gradient-to-t from-carbon via-carbon/95 to-carbon/0 px-4 pt-8 pb-[max(1rem,env(safe-area-inset-bottom))]">
                <div class="mx-auto grid max-w-xl grid-cols-[1fr_1fr_1.4fr] gap-2">
                    <button type="button" class="btn-ghost py-4 !text-red-300" @click="sheet = 'reprovado'">Reprovar</button>
                    <button type="button" class="btn-ghost py-4" @click="sheet = 'ajuste'">Correção</button>
                    <form method="POST" action="{{ route('review.store', $content) }}">
                        @csrf
                        <input type="hidden" name="decision" value="aprovado">
                        <button class="btn-verde w-full py-4">✓ Aprovar</button>
                    </form>
                </div>
            </div>

            {{-- Painel de correção / reprovação --}}
            <div x-show="sheet" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/70 backdrop-blur-sm"
                 x-transition.opacity @keydown.escape.window="sheet = null">
                <form method="POST" action="{{ route('review.store', $content) }}" @submit.prevent="submit($el)"
                      class="max-h-[94dvh] w-full max-w-xl space-y-4 overflow-y-auto rounded-t-[28px] border-t border-white/10 bg-carbon-2 px-5 pt-3 pb-[max(1.25rem,env(safe-area-inset-bottom))] [animation:sheet-up_.35s_cubic-bezier(.2,.8,.3,1)]"
                      @click.outside="if (! sending && rec !== 'recording') sheet = null">
                    @csrf
                    <input type="hidden" name="decision" :value="sheet">
                    <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-white/15"></div>

                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="eyebrow" :class="sheet === 'ajuste' ? 'text-verde-lt' : 'text-red-300'" x-text="sheet === 'ajuste' ? 'Pedir correção' : 'Reprovar'"></p>
                            <h2 class="title-serif mt-1 text-[28px] text-prata-lt" x-text="sheet === 'ajuste' ? 'O que precisa mudar?' : 'Por que não vai ao ar?'"></h2>
                        </div>
                        <button type="button" class="-mr-1 rounded-full p-2 text-prata hover:bg-white/5 hover:text-prata-lt" @click="sheet = null" aria-label="Fechar">
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 5.3a1 1 0 0 0-1.4 1.4L8.6 10l-3.7 3.3a1 1 0 1 0 1.4 1.4L10 11.4l3.7 3.3a1 1 0 0 0 1.4-1.4L11.4 10l3.7-3.3a1 1 0 0 0-1.4-1.4L10 8.6 6.3 5.3Z"/></svg>
                        </button>
                    </div>
                    <p class="text-xs text-prata-dk">Escreva, grave um áudio ou anexe um print. Basta um deles.</p>

                    <div x-show="sheet === 'ajuste'" class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5">
                        @foreach ($quick as $chip)
                            <button type="button" class="shrink-0 rounded-full border border-white/10 px-3.5 py-2 text-sm text-prata-lt/80 transition hover:border-verde/60 hover:text-prata-lt"
                                    @click="chip(@js($chip))">{{ $chip }}</button>
                        @endforeach
                    </div>

                    <textarea name="note" rows="3" x-model="note" x-ref="note"
                              class="block w-full rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-[15px] text-prata-lt placeholder:text-prata-dk focus:border-verde/60 focus:ring-4 focus:ring-verde/15 focus:outline-none"
                              placeholder="Opcional se você gravar um áudio ou mandar um print"></textarea>

                    {{-- Áudio --}}
                    <div>
                        <template x-if="rec === 'idle'">
                            <button type="button" class="btn-ghost w-full py-4" @click="start()">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
                                Gravar áudio
                            </button>
                        </template>
                        <template x-if="rec === 'recording'">
                            <button type="button" class="btn w-full bg-red-600 py-4 text-white" @click="stop()">
                                <span class="size-2.5 animate-pulse rounded-full bg-white"></span>
                                Gravando <span class="tabular-nums" x-text="clock"></span> · toque para parar
                            </button>
                        </template>
                        <template x-if="rec === 'done'">
                            <div class="rounded-xl border border-verde/40 bg-verde/10 p-3">
                                <p class="mb-2 flex items-center gap-2 text-sm font-semibold text-verde-lt">
                                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                    Áudio gravado (<span x-text="clock"></span>). Vai junto quando você enviar.
                                </p>
                                <div class="flex items-center gap-2">
                                    <audio :src="audioUrl" controls class="h-9 flex-1"></audio>
                                    <button type="button" class="rounded-lg px-2 py-1 text-xs text-red-300 hover:bg-white/5" @click="discardAudio()">Apagar</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="rec === 'unsupported'">
                            <p class="rounded-xl border border-white/10 px-4 py-3 text-xs text-prata-dk">Este navegador não grava áudio. Use o texto ou um print.</p>
                        </template>
                    </div>

                    {{-- Prints / fotos --}}
                    <div>
                        <input type="file" x-ref="picker" multiple accept="image/*" class="hidden" @change="pick($event)">
                        <button type="button" class="btn-ghost w-full py-4" @click="$refs.picker.click()" x-show="images.length < 6">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/></svg>
                            <span x-text="images.length ? 'Anexar mais um print' : 'Anexar print ou foto'"></span>
                        </button>
                        <div class="mt-2 flex flex-wrap gap-2" x-show="images.length">
                            <template x-for="(image, i) in images" :key="image.url">
                                <div class="relative">
                                    <img :src="image.url" :alt="image.name" class="size-16 rounded-lg object-cover ring-1 ring-white/10">
                                    <button type="button" class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-prata-lt text-xs text-carbon" @click="removeImage(i)">×</button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <p x-show="error" x-text="error" class="rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-200"></p>

                    <button class="btn btn-lg w-full text-white" :disabled="sending"
                            :class="sheet === 'ajuste' ? '' : 'bg-red-600 hover:bg-red-700'"
                            :style="sheet === 'ajuste' ? 'background: var(--grad-verde)' : ''">
                        <span x-show="! sending" x-text="sheet === 'ajuste' ? 'Enviar pedido de correção' : 'Reprovar conteúdo'"></span>
                        <span x-show="sending" x-cloak>Enviando…</span>
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.hub>
