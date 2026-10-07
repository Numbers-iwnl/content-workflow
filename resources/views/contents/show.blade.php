@php
    use App\Enums\Account;
    use App\Enums\ContentStatus;
    use App\Enums\ContentType;
    use App\Enums\Cta;
    use App\Enums\ReviewDecision;
    use App\Support\Pipeline;

    $status = $content->status;
    $editable = ! in_array($status, [ContentStatus::Postado, ContentStatus::Arquivado], true);
    $inbox = $status === ContentStatus::Novo;
    $missing = $content->missingForPlanilha();
    $decisions = $content->decisions();
    $pendingCorrections = $content->hasPendingCorrection()
        ? $decisions->filter(fn ($r) => $r->decision === ReviewDecision::Ajuste)
        : collect();
    $accounts = collect(Account::cases());
@endphp

<x-layouts.workspace :title="$content->title" :active-stage="$stage">
    {{-- Navegação --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('contents.index', ['etapa' => $stage]) }}" class="eyebrow flex items-center gap-2 text-slate hover:text-grafite">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.8 4.2a1 1 0 0 1 0 1.4L8.4 10l4.4 4.4a1 1 0 0 1-1.4 1.4l-5.1-5.1a1 1 0 0 1 0-1.4l5.1-5.1a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
            {{ Pipeline::STAGES[$stage]['label'] }}
        </a>
        @if ($position !== false && $total > 1)
            <div class="flex items-center gap-2 text-sm text-slate">
                <span class="tabular-nums">{{ $position + 1 }} de {{ $total }}</span>
                <a @if ($previousId) href="{{ route('contents.show', $previousId) }}" @endif @class(['rounded-lg border border-silver bg-white p-2', 'pointer-events-none opacity-30' => ! $previousId, 'hover:border-mist' => $previousId]) title="Anterior">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.8 4.2a1 1 0 0 1 0 1.4L8.4 10l4.4 4.4a1 1 0 0 1-1.4 1.4l-5.1-5.1a1 1 0 0 1 0-1.4l5.1-5.1a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                </a>
                <a @if ($nextId) href="{{ route('contents.show', $nextId) }}" @endif @class(['rounded-lg border border-silver bg-white p-2', 'pointer-events-none opacity-30' => ! $nextId, 'hover:border-mist' => $nextId]) title="Próximo">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.2 15.8a1 1 0 0 1 0-1.4l4.4-4.4-4.4-4.4a1 1 0 0 1 1.4-1.4l5.1 5.1a1 1 0 0 1 0 1.4l-5.1 5.1a1 1 0 0 1-1.4 0Z" clip-rule="evenodd"/></svg>
                </a>
            </div>
        @endif
    </div>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,420px)_1fr]">
        {{-- Prévia --}}
        <aside class="space-y-4 lg:sticky lg:top-8 lg:self-start">
            <div class="overflow-hidden rounded-[var(--radius-card)] bg-black shadow-xl shadow-grafite/10">
                <x-media :content="$content" />
            </div>
            <dl class="card divide-y divide-paper-2 text-sm">
                <div class="flex justify-between gap-4 px-4 py-3"><dt class="text-slate">Chegou em</dt><dd class="text-right text-ink">{{ $content->drive_created_at?->format('d/m/Y · H:i') }}</dd></div>
                <div class="px-4 py-3"><dt class="text-slate">Pasta no Drive</dt><dd class="mt-1 text-xs leading-relaxed break-words text-ink">{{ $content->drive_path }}</dd></div>
                @foreach ($content->files as $file)
                    <div class="px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="min-w-0 truncate text-xs text-ink" title="{{ $file->name }}">{{ $file->name }}</span>
                            <a href="{{ $file->web_view_link }}" target="_blank" rel="noopener" class="eyebrow shrink-0 text-[10px] text-verde-dk hover:underline">Drive ↗</a>
                        </div>
                        @if ($specs = $file->specs())
                            <p class="mt-1 text-xs text-slate tabular-nums">{{ $specs }}</p>
                        @endif
                    </div>
                @endforeach
            </dl>
        </aside>

        <div class="min-w-0 space-y-5">
            <header>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    @if ($status !== ContentStatus::Pendente)
                        <x-status :status="$status" />
                    @endif
                    @if ($content->scheduled_for)
                        <span class="text-sm text-slate">agendado para <b class="text-ink">{{ ucfirst($content->scheduled_for->translatedFormat('D, d/m · H:i')) }}</b></span>
                    @endif
                    @if ($content->round > 1)
                        <span class="text-sm text-slate/70">· {{ $content->round - 1 }}ª correção</span>
                    @endif
                </div>
                <h1 class="title-serif text-[34px] text-grafite sm:text-[40px]">{{ $content->title }}</h1>
            </header>

            {{-- Aprovação: só o que a Ana envia vai para a Bruna e a Carla --}}
            @if ($status->inPlanilha() || $status === ContentStatus::Postado)
                @if (! $content->needs_approval)
                    <div class="card flex flex-wrap items-center justify-between gap-3 px-4 py-3.5">
                        <p class="text-sm text-slate">Não enviado para aprovação. <span class="text-slate/70">Nem todo conteúdo precisa (vídeo de tráfego, por exemplo).</span></p>
                        @if ($status->inPlanilha())
                            <form method="POST" action="{{ route('contents.action', [$content, 'pedir-aprovacao']) }}">@csrf<button class="btn-verde btn-sm">Enviar para a Bruna e a Carla</button></form>
                        @endif
                    </div>
                @else
                    <div class="space-y-2">
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($approvers as $approver)
                                @php
                                    $decision = $decisions->get($approver->id);
                                @endphp
                                <div class="card flex items-center justify-between gap-3 px-4 py-3">
                                    <span class="font-medium text-ink">{{ $approver->name }}</span>
                                    <span @class(['rounded-full px-2.5 py-1 text-xs font-semibold', $decision ? $decision->decision->color() : 'bg-paper-2 text-slate'])>
                                        {{ $decision?->decision->short() ?? 'Aguardando' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        @if ($status->inPlanilha() && ! $content->isRejected())
                            <form method="POST" action="{{ route('contents.action', [$content, 'retirar-aprovacao']) }}" class="text-right">
                                @csrf
                                <button class="text-xs text-slate underline-offset-2 hover:text-ink hover:underline">Tirar da aprovação</button>
                            </form>
                        @endif
                    </div>
                @endif

                @if ($content->isRejected())
                    <div class="rounded-2xl border border-red-200 bg-red-50/70 p-5" x-data="{ step: 0, ok: false }">
                        <p class="eyebrow text-red-700">Reprovado</p>
                        <p class="mt-2 text-sm text-red-900/80">Se foi engano ou o conteúdo mudou, dá para reverter: as opiniões da Bruna e da Carla sobre ele são zeradas e ele volta para a aprovação. O histórico continua guardado.</p>
                        <button type="button" x-show="step === 0" class="btn-danger btn-sm mt-4" @click="step = 1">Reverter reprovação</button>

                        <form x-show="step === 1" x-cloak method="POST" action="{{ route('contents.action', [$content, 'reverter']) }}" class="mt-4 rounded-xl border border-red-200 bg-white p-4">
                            @csrf
                            <p class="text-sm font-semibold text-ink">Tem certeza?</p>
                            <label class="mt-2 flex items-start gap-2 text-sm text-slate">
                                <input type="checkbox" name="confirmo" value="1" x-model="ok" class="mt-0.5 size-4 rounded accent-red-600">
                                Entendo que as aprovações e reprovações atuais deixam de valer.
                            </label>
                            <div class="mt-3 flex items-center gap-3">
                                <button class="btn btn-sm bg-red-600 text-white hover:bg-red-700" :disabled="! ok">Sim, reverter</button>
                                <button type="button" class="text-sm text-slate" @click="step = 0; ok = false">cancelar</button>
                            </div>
                        </form>
                    </div>
                @endif
            @endif

            {{-- Avisos --}}
            @if ($content->suggestedCorrectionOf?->hasPendingCorrection())
                <div class="rounded-2xl border border-cyan-200 bg-cyan-50 p-5">
                    <p class="text-[15px] text-cyan-950">Este arquivo parece ser a <b>correção</b> de “<a class="underline underline-offset-2" href="{{ route('contents.show', $content->suggestedCorrectionOf) }}">{{ $content->suggestedCorrectionOf->title }}</a>”, que estava esperando ajuste.</p>
                    <form method="POST" action="{{ route('contents.action', [$content, 'vincular']) }}" class="mt-4">
                        @csrf
                        <button class="btn-grafite btn-sm">Sim, substituir o arquivo antigo por este</button>
                    </form>
                </div>
            @endif

            @if ($pendingCorrections->isNotEmpty())
                <div class="rounded-2xl border border-amber-200 bg-amber-50/70 p-5">
                    <p class="eyebrow mb-3 text-amber-800">Correção pedida</p>
                    <x-review-history :reviews="$pendingCorrections->values()" />
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('contents.action', [$content, 'corrigido']) }}">@csrf<button class="btn-grafite btn-sm">Marcar como corrigido</button></form>
                        <p class="text-xs text-amber-900/70">Se o arquivo for substituído no Drive, o sistema percebe sozinho.</p>
                    </div>
                </div>
            @endif

            {{-- Agendar / postar --}}
            @if ($status->inPlanilha())
                <div class="card p-5">
                    <p class="eyebrow mb-4 text-verde-dk">Publicação</p>
                    <div class="flex flex-wrap items-end gap-3">
                        <form method="POST" action="{{ route('contents.action', [$content, 'agendar']) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <div><label class="label" for="data">Data</label><input id="data" type="date" name="data" class="field py-2.5" value="{{ $content->scheduled_for?->format('Y-m-d') }}" required></div>
                            <div><label class="label" for="hora">Hora</label><input id="hora" type="time" name="hora" class="field py-2.5" value="{{ $content->scheduled_for?->format('H:i') }}" required></div>
                            <button class="btn-outline">{{ $content->scheduled_for ? 'Reagendar' : 'Agendar' }}</button>
                        </form>
                        @if ($status === ContentStatus::Agendado)
                            <form method="POST" action="{{ route('contents.action', [$content, 'desagendar']) }}">@csrf<button class="btn-quiet">Tirar da agenda</button></form>
                        @endif
                        <form method="POST" action="{{ route('contents.action', [$content, 'postado']) }}" class="sm:ml-auto">@csrf<button class="btn-grafite">Marcar como postado</button></form>
                    </div>
                </div>
            @endif

            {{-- Arquivado: trazer de volta ou excluir de vez --}}
            @if ($status === ContentStatus::Arquivado)
                <div class="card p-5" x-data="{ deleting: false, typed: '' }">
                    <p class="text-sm text-slate">Este conteúdo está arquivado.</p>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <form method="POST" action="{{ route('contents.action', [$content, 'restaurar']) }}">@csrf<button class="btn-verde btn-lg">↺ Trazer de volta para a caixa de entrada</button></form>
                        <button type="button" class="btn-danger btn-sm" @click="deleting = true">Excluir definitivamente</button>
                    </div>

                    <div x-show="deleting" x-cloak x-transition class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4">
                        <p class="text-sm font-semibold text-red-900">Tem certeza? Isso não tem volta.</p>
                        <p class="mt-1 text-sm text-red-900/80">O conteúdo, a legenda e as aprovações saem do sistema. O arquivo no Google Drive <b>não</b> é apagado, e não volta a aparecer na caixa de entrada.</p>
                        <form method="POST" action="{{ route('contents.action', [$content, 'excluir']) }}" class="mt-3 flex flex-wrap items-center gap-2">
                            @csrf
                            <input name="confirmacao" x-model="typed" class="field w-48 py-2 text-sm" placeholder="Digite EXCLUIR" autocomplete="off">
                            <button class="btn btn-sm bg-red-600 text-white hover:bg-red-700" :disabled="typed !== 'EXCLUIR'">Excluir de vez</button>
                            <button type="button" class="text-sm text-slate" @click="deleting = false; typed = ''">cancelar</button>
                        </form>
                    </div>
                </div>
            @endif

            @if ($status === ContentStatus::Postado)
                <div class="card flex flex-wrap items-center justify-between gap-3 p-4">
                    <p class="text-sm text-slate">Postado em {{ $content->posted_at?->format('d/m/Y') }}.</p>
                    <form method="POST" action="{{ route('contents.action', [$content, 'restaurar']) }}">@csrf<button class="btn-quiet btn-sm">Trazer de volta para a caixa de entrada</button></form>
                </div>
            @endif

            {{-- Dados do post --}}
            <form method="POST" action="{{ route('contents.update', $content) }}" id="content-form" class="card divide-y divide-paper-2">
                @csrf
                @method('PUT')
                <fieldset @disabled(! $editable) class="contents">
                    <section class="space-y-5 p-5 sm:p-6">
                        @if ($inbox)
                            <p class="rounded-xl bg-paper px-4 py-3 text-sm text-slate">
                                Preencha <b class="text-ink">área, tipo e CTA</b>. Ao salvar com os três, o conteúdo vai para a planilha.
                            </p>
                        @endif
                        <div>
                            <span class="label flex items-center gap-2">Área · onde vai ser postado @if (in_array('área', $missing)) <span class="size-1.5 rounded-full bg-amber-500"></span> @endif</span>
                            <x-seg name="account" :options="$accounts->mapWithKeys(fn ($a) => [$a->value => $a->label()])" :colors="$accounts->mapWithKeys(fn ($a) => [$a->value => $a->color()])->all()" :value="old('account', $content->account?->value)" />
                        </div>
                        <div>
                            <span class="label">Collab com <span class="tracking-normal normal-case text-slate/60">(opcional)</span></span>
                            <x-seg name="collab_accounts" multiple :options="$accounts->mapWithKeys(fn ($a) => [$a->value => $a->label()])" :value="old('collab_accounts', $content->collab_accounts ?? [])" />
                        </div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <span class="label flex items-center gap-2">Tipo @if (in_array('tipo', $missing)) <span class="size-1.5 rounded-full bg-amber-500"></span> @endif</span>
                                <x-seg name="type" :options="collect(ContentType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :value="old('type', $content->type?->value)" />
                            </div>
                            <div>
                                <span class="label flex items-center gap-2">CTA @if (in_array('CTA', $missing)) <span class="size-1.5 rounded-full bg-amber-500"></span> @endif</span>
                                <x-seg name="cta" :options="collect(Cta::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])" :value="old('cta', $content->cta?->value)" />
                            </div>
                        </div>
                    </section>

                    <section class="p-5 sm:p-6" x-data="{ text: @js(old('caption', $content->caption ?? '')) }">
                        <div class="mb-2 flex items-end justify-between gap-3">
                            <label for="caption" class="label mb-0">Legenda <span class="tracking-normal normal-case text-slate/60">(pode ficar para depois)</span></label>
                            <span class="text-xs tabular-nums text-slate/70">
                                <span :class="(text.match(/#[\p{L}\d_]+/gu) || []).length > 30 && 'font-semibold text-red-600'" x-text="`${(text.match(/#[\p{L}\d_]+/gu) || []).length}/30 hashtags`"></span>
                                ·
                                <span :class="text.length > 2200 && 'font-semibold text-red-600'" x-text="`${text.length}/2200`"></span>
                            </span>
                        </div>
                        <textarea id="caption" name="caption" rows="11" x-model="text"
                                  class="field resize-y text-[15.5px] leading-relaxed" placeholder="Escreva a legenda do post…"></textarea>
                    </section>

                    <section class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
                        <div class="sm:col-span-2">
                            <label class="label" for="title">Título / assunto</label>
                            <input id="title" name="title" class="field" value="{{ old('title', $content->title) }}" required>
                            <p class="mt-1.5 text-xs text-slate/70">É o nome que aparece na planilha e nas listas. Vem do nome do arquivo; ajuste se quiser.</p>
                        </div>
                        <div>
                            <label class="label" for="project">Projeto</label>
                            <input id="project" name="project" list="projects" class="field" value="{{ old('project', $content->project) }}" placeholder="Ex.: Cortes de palestras">
                            <datalist id="projects">@foreach ($projects as $p)<option value="{{ $p }}">@endforeach</datalist>
                        </div>
                        <div>
                            <label class="label" for="produced_by">Quem produziu</label>
                            <input id="produced_by" name="produced_by" list="producers" class="field" value="{{ old('produced_by', $content->produced_by) }}">
                            <datalist id="producers">@foreach ($producers as $p)<option value="{{ $p }}">@endforeach</datalist>
                        </div>
                    </section>
                </fieldset>
            </form>

            {{-- Histórico --}}
            @if ($content->reviews->isNotEmpty())
                <section class="pt-2">
                    <h2 class="title-serif mb-4 text-2xl text-grafite">Aprovações</h2>
                    <x-review-history :reviews="$content->reviews" />
                </section>
            @endif

            <details class="group pt-2">
                <summary class="eyebrow cursor-pointer list-none text-slate hover:text-grafite">
                    <span class="group-open:hidden">+ Ver histórico completo</span><span class="hidden group-open:inline">− Histórico completo</span>
                </summary>
                <ol class="mt-4 space-y-2 border-l border-silver pl-4 text-sm text-slate">
                    @foreach ($content->events as $event)
                        <li class="relative">
                            <span class="absolute top-2 -left-[21px] size-2 rounded-full bg-mist"></span>
                            <span class="text-xs text-slate/60 tabular-nums">{{ $event->created_at->format('d/m H:i') }}</span>
                            <span class="text-ink">{{ ucfirst(str_replace('_', ' ', $event->type)) }}</span>
                            @if ($event->to_status && ($to = ContentStatus::tryFrom($event->to_status)) && $to !== ContentStatus::Pendente) → {{ $to->label() }}@endif
                            <span class="text-slate/70">· {{ $event->user?->name ?? 'sistema' }}</span>
                            @if ($event->data['motivo'] ?? null) <span class="italic">“{{ $event->data['motivo'] }}”</span>@endif
                        </li>
                    @endforeach
                </ol>
            </details>
        </div>
    </div>

    {{-- Barra de ações fixa --}}
    @if ($editable)
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-paper-2 bg-white/90 backdrop-blur-md lg:left-64">
            <div class="mx-auto flex max-w-[1280px] flex-wrap items-center gap-2 px-4 py-3 sm:px-8" x-data="{ archiving: false }">
                <form method="POST" action="{{ route('contents.action', [$content, 'arquivar']) }}" class="flex items-center gap-2">
                    @csrf
                    <input x-show="archiving" x-cloak name="motivo" class="field w-48 py-2 text-sm" placeholder="Motivo (opcional)" x-ref="motivo">
                    <button type="button" x-show="! archiving" class="btn-quiet btn-sm" @click="archiving = true; $nextTick(() => $refs.motivo.focus())">Arquivar</button>
                    <button x-show="archiving" x-cloak class="btn-danger btn-sm">Confirmar</button>
                    <button type="button" x-show="archiving" x-cloak class="text-xs text-slate" @click="archiving = false">cancelar</button>
                </form>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    @if ($missing && $inbox)
                        <span class="hidden text-xs text-amber-700 md:inline">Falta: {{ implode(', ', $missing) }}</span>
                    @endif
                    <button form="content-form" @class(['btn-verde' => $inbox, 'btn-grafite' => ! $inbox])>
                        {{ $inbox ? 'Salvar' : 'Salvar alterações' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-layouts.workspace>
