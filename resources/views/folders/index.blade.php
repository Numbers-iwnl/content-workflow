<x-layouts.workspace title="Pastas do Drive">
    <header class="mb-8 max-w-2xl">
        <p class="eyebrow text-verde-dk">Ferramentas</p>
        <h1 class="title-serif mt-2 text-[44px] text-grafite sm:text-[56px]">Pastas do Drive</h1>
        <p class="mt-3 text-[15px] leading-relaxed text-slate">Arquivos novos nestas pastas (ou em qualquer subpasta) entram na caixa de entrada em até 5 minutos. O que já existia quando a pasta foi cadastrada não entra.</p>
    </header>

    {{-- Saúde da leitura automática (cron a cada minuto, Drive a cada 5) --}}
    @php
        $healthy = $lastSync && $lastSync->gt(now()->subMinutes(20));
        $errorAt = isset($lastError['at']) ? \Illuminate\Support\Carbon::parse($lastError['at']) : null;
    @endphp
    <div @class(['mb-8 max-w-5xl rounded-[var(--radius-card)] border p-5',
                 'border-emerald-200 bg-emerald-50/70' => $healthy && ! $lastError,
                 'border-amber-200 bg-amber-50/70' => ! $healthy || $lastError])>
        <div class="flex items-start gap-3">
            <span @class(['mt-1.5 size-2.5 shrink-0 rounded-full', 'bg-emerald-500' => $healthy && ! $lastError, 'bg-amber-500' => ! $healthy || $lastError])></span>
            <div class="min-w-0 text-sm">
                @if ($healthy)
                    <p class="font-semibold text-ink">Leitura automática funcionando</p>
                    <p class="mt-0.5 text-slate">O Drive foi lido {{ $lastSync->diffForHumans() }} ({{ $lastSync->format('d/m H:i') }}). Ele é lido a cada 5 minutos.</p>
                @elseif ($lastSync)
                    <p class="font-semibold text-ink">A leitura automática está parada</p>
                    <p class="mt-0.5 text-slate">Última leitura do Drive: {{ $lastSync->diffForHumans() }}. Verifique a tarefa cron na Hostinger.</p>
                @else
                    <p class="font-semibold text-ink">O Drive ainda não foi lido</p>
                    <p class="mt-0.5 text-slate">A primeira leitura acontece até 5 minutos depois que a tarefa cron começa a rodar.</p>
                @endif
                @if ($lastError)
                    <p class="mt-3 rounded-lg bg-white/70 px-3 py-2 font-mono text-xs break-words text-amber-900">
                        {{ $errorAt?->format('d/m H:i') }} · {{ $lastError['message'] }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    <div class="grid max-w-5xl gap-8 lg:grid-cols-[1fr_340px]">
        <ul class="space-y-3 self-start">
            @forelse ($folders as $folder)
                <li class="card flex flex-wrap items-center gap-4 p-4 sm:px-5">
                    <span @class(['flex size-11 shrink-0 items-center justify-center rounded-xl', 'bg-verde/10 text-verde' => $folder->active, 'bg-paper-2 text-slate' => ! $folder->active])>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <a href="https://drive.google.com/drive/folders/{{ $folder->drive_folder_id }}" target="_blank" rel="noopener" class="font-medium text-ink hover:underline">{{ $folder->name }}</a>
                        @unless ($folder->active)<span class="ml-2 rounded-full bg-paper-2 px-2 py-0.5 text-xs text-slate">pausada</span>@endunless
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-slate">
                            @if ($folder->default_account)<span class="flex items-center gap-1.5"><x-account-dot :account="$folder->default_account" size="xs" />{{ $folder->default_account->label() }}</span> ·@endif
                            <span>{{ $folder->imported_count }} importados</span> ·
                            <span>{{ $folder->ignored_count }} ignorados</span>
                        </p>
                        <p class="mt-0.5 text-xs text-slate/70">
                            @if ($folder->baseline_completed_at) varredura completa {{ $folder->last_reconciled_at?->diffForHumans() }} (toda noite) @else <b class="text-amber-700">registrando arquivos existentes…</b> @endif
                        </p>
                    </div>
                    <form method="POST" action="{{ route('folders.toggle', $folder) }}">
                        @csrf
                        <button class="btn-outline btn-sm">{{ $folder->active ? 'Pausar' : 'Reativar' }}</button>
                    </form>
                </li>
            @empty
                <li class="rounded-[var(--radius-card)] border border-dashed border-silver bg-white/60 p-10 text-center text-sm text-slate">Nenhuma pasta ainda.</li>
            @endforelse
        </ul>

        <div class="space-y-6 self-start">
        <form method="POST" action="{{ route('folders.backfill') }}" class="card space-y-4 p-5">
            @csrf
            <h2 class="title-serif text-2xl text-grafite">Trazer o que já estava nas pastas</h2>
            <p class="text-sm text-slate">Arquivos que já existiam quando as pastas foram cadastradas não entram sozinhos. Escolha a partir de quando trazer: eles vão para a caixa de entrada, passando pelas mesmas regras (sem brutos, fotos de câmera etc.).</p>
            <div>
                <label class="label" for="desde">Criados desde</label>
                <input id="desde" type="date" name="desde" class="field" value="{{ old('desde', now()->subWeek()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required>
            </div>
            <button class="btn-grafite w-full">Trazer para a caixa de entrada</button>
            @if ($lastBackfill)
                <p class="text-xs text-slate/80">
                    Última vez: desde {{ \Illuminate\Support\Carbon::parse($lastBackfill['since'])->format('d/m/Y') }},
                    {{ $lastBackfill['imported'] }} {{ $lastBackfill['imported'] === 1 ? 'arquivo trazido' : 'arquivos trazidos' }}
                    ({{ \Illuminate\Support\Carbon::parse($lastBackfill['at'])->diffForHumans() }}).
                </p>
            @endif
        </form>

        <form method="POST" action="{{ route('folders.store') }}" class="card space-y-5 p-5">
            @csrf
            <h2 class="title-serif text-2xl text-grafite">Monitorar outra pasta</h2>
            <div class="rounded-xl bg-paper p-3 text-xs leading-relaxed text-slate" x-data="copy(@js($serviceAccount))">
                Antes, compartilhe a pasta no Drive como <b>Leitor</b> com:
                <button type="button" class="mt-1 block w-full truncate rounded-lg bg-white px-2 py-1.5 text-left font-mono text-[11px] text-ink ring-1 ring-silver" @click="copy" :title="copied ? 'Copiado' : 'Copiar'">
                    <span x-text="copied ? 'Copiado ✓' : @js($serviceAccount)"></span>
                </button>
            </div>
            <div>
                <label class="label" for="link">Link da pasta</label>
                <input id="link" name="link" class="field" value="{{ old('link') }}" placeholder="https://drive.google.com/drive/folders/…" required>
            </div>
            <div>
                <span class="label">Conta padrão</span>
                <x-seg name="account" :options="collect(\App\Enums\Account::cases())->mapWithKeys(fn ($a) => [$a->value => $a->label()])->prepend('Ana escolhe', '')" :value="old('account', '')" />
            </div>
            <button class="btn-grafite w-full">Cadastrar pasta</button>
        </form>
        </div>
    </div>
</x-layouts.workspace>
