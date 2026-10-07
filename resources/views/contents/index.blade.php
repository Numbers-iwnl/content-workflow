@php
    use App\Enums\Account;
    use App\Enums\ContentStatus;
    use App\Support\Pipeline;

    $info = Pipeline::STAGES[$stage];
    $items = $contents->getCollection();
    $groups = $stage === 'aprovacao'
        ? $approvers->mapWithKeys(fn ($a) => ['Esperando '.$a->name => $items->filter(fn ($c) => $c->isAwaiting($a))])->filter->isNotEmpty()
        : collect(['' => $items]);
    $readyCount = $selectable ? $items->filter(fn ($c) => ! $c->missingForPlanilha())->count() : 0;
    $empty = [
        'entrada' => ['Tudo em dia.', 'Assim que a equipe subir um arquivo nas pastas do Drive, ele aparece aqui em até 5 minutos.'],
        'aprovacao' => ['Nada esperando aprovação.', 'Tudo o que está na planilha já tem a opinião da Bruna e da Carla.'],
        'postados' => ['Nada postado ainda.', 'Marque como postado na página do conteúdo ou na planilha.'],
        'arquivados' => ['Nada arquivado.', 'Conteúdos arquivados ficam guardados aqui.'],
    ][$stage];
@endphp

<x-layouts.workspace :title="$info['label']">
    <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div class="max-w-2xl">
            <p class="eyebrow text-verde-dk">Fluxo de conteúdo</p>
            <h1 class="title-serif mt-2 text-[44px] text-grafite sm:text-[56px]">{{ $info['label'] }}</h1>
            <p class="mt-3 text-[15px] leading-relaxed text-slate">{{ $info['hint'] }}</p>
        </div>
    </header>

    {{-- Filtros --}}
    <form method="GET" class="mb-6 flex flex-wrap items-center gap-2">
        <input type="hidden" name="etapa" value="{{ $stage }}">
        @if (request('conta'))<input type="hidden" name="conta" value="{{ request('conta') }}">@endif
        <label class="relative min-w-56 flex-1 sm:max-w-sm">
            <svg class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="busca" value="{{ request('busca') }}" placeholder="Buscar título, projeto ou legenda" class="field py-2.5 pl-10">
        </label>
        <div class="flex flex-wrap gap-1.5">
            <a href="{{ request()->fullUrlWithQuery(['conta' => null, 'page' => null]) }}"
               @class(['rounded-full px-3.5 py-2 text-sm transition', 'bg-grafite text-white' => ! request('conta'), 'bg-white text-slate ring-1 ring-silver hover:ring-mist' => request('conta')])>Todas</a>
            @foreach (Account::cases() as $account)
                <a href="{{ request()->fullUrlWithQuery(['conta' => $account->value, 'page' => null]) }}"
                   @class(['flex items-center gap-2 rounded-full py-1.5 pr-3.5 pl-1.5 text-sm transition', 'bg-grafite text-white' => request('conta') === $account->value, 'bg-white text-slate ring-1 ring-silver hover:ring-mist' => request('conta') !== $account->value])>
                    <x-account-dot :account="$account" size="xs" />{{ $account->label() }}
                </a>
            @endforeach
        </div>
    </form>

    @if ($items->isEmpty())
        <div class="rise rounded-[var(--radius-card)] border border-dashed border-silver bg-white/60 px-6 py-20 text-center">
            <p class="title-serif text-3xl text-grafite">{{ $empty[0] }}</p>
            <p class="mx-auto mt-3 max-w-md text-sm leading-relaxed text-slate">{{ $empty[1] }}</p>
        </div>
    @else
        <form method="POST" action="{{ route('contents.move-many') }}" id="move-form" x-data="{ selected: [] }">
            @csrf

            @if ($selectable && $readyCount)
                <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
                    <button type="button" class="btn-outline btn-sm"
                            @click="selected = [...$root.querySelectorAll('[data-ready]')].map(i => i.value)">
                        Selecionar os {{ $readyCount }} prontos
                    </button>
                    <button type="button" x-show="selected.length" x-cloak class="text-slate underline-offset-4 hover:underline" @click="selected = []">Limpar seleção</button>
                </div>
            @endif

            @foreach ($groups as $groupLabel => $groupItems)
                @if ($groupLabel)
                    <h2 class="mt-2 mb-4 flex items-center gap-3">
                        <span class="title-serif text-2xl text-grafite">{{ $groupLabel }}</span>
                        <span class="rounded-full bg-grafite/[0.08] px-2.5 py-0.5 text-xs font-semibold text-grafite">{{ $groupItems->count() }}</span>
                    </h2>
                @endif

                <ul class="mb-10 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    @foreach ($groupItems as $content)
                        @php
                            $missing = $content->missingForPlanilha();
                            $decisions = $content->decisions();
                        @endphp
                        <li class="rise group relative" style="animation-delay: {{ min($loop->index * 30, 400) }}ms">
                            <a href="{{ route('contents.show', $content) }}"
                               class="block overflow-hidden rounded-[var(--radius-card)] border border-paper-2 bg-white transition duration-300 hover:-translate-y-1 hover:border-silver hover:shadow-xl hover:shadow-grafite/[0.07]"
                               :class="selected.includes('{{ $content->id }}') && 'ring-2 ring-verde ring-offset-2 ring-offset-paper'">
                                <x-poster :content="$content" class="rounded-t-[calc(var(--radius-card)-1px)]" />
                                <div class="p-3.5">
                                    <div class="flex items-start gap-2">
                                        <x-account-dot :account="$content->account" size="xs" class="mt-0.5" />
                                        <p class="line-clamp-2 text-[14px] leading-snug font-medium text-ink">{{ $content->title }}</p>
                                    </div>
                                    <p class="mt-1.5 truncate text-xs text-slate/80">
                                        {{ collect([$content->project, $content->produced_by])->filter()->join(' · ') ?: '—' }}
                                    </p>

                                    <div class="mt-3 text-xs">
                                        @switch($stage)
                                            @case('entrada')
                                                @if ($missing)
                                                    <span class="inline-flex rounded-full bg-amber-500/15 px-2 py-1 font-medium text-amber-800">Falta: {{ implode(', ', $missing) }}</span>
                                                @else
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-verde/15 px-2 py-1 font-semibold text-verde-dk">✓ Pronto para a planilha</span>
                                                @endif
                                                @if ($content->suggestedCorrectionOf)
                                                    <p class="mt-2 line-clamp-2 rounded-lg bg-cyan-50 px-2 py-1.5 text-cyan-900">Parece a correção de “{{ $content->suggestedCorrectionOf->title }}”</p>
                                                @endif
                                                @break
                                            @case('aprovacao')
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach ($approvers as $approver)
                                                        @php
                                                            $d = $decisions->get($approver->id);
                                                        @endphp
                                                        <span @class(['rounded-full px-2 py-0.5 font-medium', $d ? $d->decision->color() : 'bg-paper-2 text-slate'])>{{ $approver->name }}: {{ $d?->decision->short() ?? '—' }}</span>
                                                    @endforeach
                                                </div>
                                                @if ($content->status === ContentStatus::Corrigido)
                                                    <span class="mt-1.5 inline-flex rounded-full bg-cyan-500/15 px-2 py-0.5 font-semibold text-cyan-800">Corrigido</span>
                                                @endif
                                                @break
                                            @case('postados')
                                                <span class="text-slate">postado {{ $content->posted_at?->format('d/m/Y') }}</span>
                                                @break
                                            @default
                                                <span class="text-slate">arquivado {{ $content->updated_at->diffForHumans() }}</span>
                                        @endswitch
                                    </div>
                                </div>
                            </a>

                            @if ($stage === 'arquivados')
                                <form method="POST" action="{{ route('contents.action', [$content, 'restaurar']) }}" class="mt-2">
                                    @csrf
                                    <button class="btn-verde btn-sm w-full">↺ Trazer de volta</button>
                                </form>
                            @endif

                            @if ($selectable)
                                <label class="absolute top-2.5 right-2.5 z-10 flex size-8 cursor-pointer items-center justify-center rounded-full bg-white/90 shadow-md ring-1 ring-black/5 backdrop-blur transition hover:scale-105"
                                       :class="selected.includes('{{ $content->id }}') && '!bg-verde'" title="Selecionar">
                                    <input type="checkbox" name="ids[]" value="{{ $content->id }}" x-model="selected" class="sr-only" @if (! $missing) data-ready @endif>
                                    <svg class="size-4" :class="selected.includes('{{ $content->id }}') ? 'text-white' : 'text-mist'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                </label>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach

            @if ($selectable)
                <div x-show="selected.length" x-cloak x-transition.opacity
                     class="fixed inset-x-0 bottom-0 z-30 p-4 lg:left-64">
                    <div class="mx-auto flex max-w-xl items-center justify-between gap-3 rounded-2xl bg-carbon p-3 pl-5 text-white shadow-2xl shadow-carbon/40">
                        <p class="text-sm"><b class="text-verde-lt" x-text="selected.length"></b> <span x-text="selected.length === 1 ? 'selecionado' : 'selecionados'"></span></p>
                        <button class="btn-verde">Mover para a planilha</button>
                    </div>
                </div>
            @endif
        </form>

        <div class="mt-2">{{ $contents->links() }}</div>
    @endif
</x-layouts.workspace>
