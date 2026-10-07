@props(['title' => null, 'activeStage' => null])
@php
    use App\Support\Pipeline;

    $current = request()->routeIs('contents.index') ? request('etapa', 'entrada') : null;
    $tools = [
        ['people.index', 'Pessoas e acessos', 'M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM21 19v-1a4 4 0 0 0-3-3.87M15.5 3.13a3.5 3.5 0 0 1 0 6.75'],
        ['folders.index', 'Pastas do Drive', 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z'],
    ];
@endphp

<x-layouts.base :title="$title" body-class="bg-paper text-ink">
    <div x-data="{ menu: false }" class="lg:flex">
        {{-- Barra do topo (celular) --}}
        <header class="sticky top-0 z-40 flex items-center justify-between bg-carbon px-4 py-3 text-white lg:hidden">
            <a href="{{ route('contents.index') }}" class="flex items-baseline gap-2">
                <span class="title-serif text-[22px]">Conteúdos</span>
                <span class="eyebrow text-[9px] text-verde">EC</span>
            </a>
            <button type="button" class="rounded-lg p-2 text-mist hover:bg-white/10" @click="menu = true" aria-label="Abrir menu">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </header>

        {{-- Menu lateral --}}
        <div x-show="menu" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-carbon/60 backdrop-blur-sm lg:hidden" @click="menu = false"></div>
        <aside :class="menu ? 'translate-x-0' : '-translate-x-full'"
               class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-carbon text-mist transition-transform duration-300 lg:sticky lg:top-0 lg:h-dvh lg:w-64 lg:shrink-0 lg:translate-x-0">
            <div class="px-6 pt-7 pb-8">
                <a href="{{ route('contents.index') }}" class="block">
                    <span class="eyebrow block text-[10px] text-verde">Estúdio de Conteúdo</span>
                    <span class="title-serif mt-1 block text-[30px] text-white">Conteúdos</span>
                </a>
            </div>

            <nav class="flex-1 space-y-7 overflow-y-auto px-3 text-[14.5px]">
                <div>
                    <p class="eyebrow mb-2 px-3 text-[10px] text-mist/50">Fluxo</p>
                    @foreach (Pipeline::STAGES as $key => $stage)
                        @php
                            $active = $current === $key || $activeStage === $key;
                        @endphp
                        <a href="{{ route('contents.index', ['etapa' => $key]) }}"
                           @class(['group relative flex items-center justify-between rounded-lg px-3 py-2.5 transition',
                                   'bg-white/[0.07] text-white' => $active,
                                   'hover:bg-white/[0.04] hover:text-white' => ! $active])>
                            @if ($active)<span class="absolute top-2 bottom-2 left-0 w-[3px] rounded-full bg-verde"></span>@endif
                            <span>{{ $stage['label'] }}</span>
                            @if ($stageCounts[$key])
                                <span @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums',
                                              'bg-verde text-white' => $key === 'entrada',
                                              'bg-white/10 text-mist' => $key !== 'entrada'])>{{ $stageCounts[$key] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>

                <div>
                    <p class="eyebrow mb-2 px-3 text-[10px] text-mist/50">Ferramentas</p>
                    @foreach ($tools as [$route, $label, $icon])
                        <a href="{{ route($route) }}"
                           @class(['flex items-center gap-3 rounded-lg px-3 py-2.5 transition',
                                   'bg-white/[0.07] text-white' => request()->routeIs($route),
                                   'hover:bg-white/[0.04] hover:text-white' => ! request()->routeIs($route)])>
                            <svg class="size-[18px] shrink-0 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg>
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </nav>

            {{-- Sinal de vida da sincronização automática (cron) --}}
            @php
                $syncOk = $lastSync && $lastSync->gt(now()->subMinutes(20));
            @endphp
            <a href="{{ route('folders.index') }}" class="mx-3 mb-3 flex items-center gap-2 rounded-lg px-3 py-2 text-xs text-mist/60 hover:bg-white/[0.04]"
               title="{{ $lastSync ? 'Última leitura do Drive: '.$lastSync->format('d/m H:i') : 'O Drive ainda não foi lido' }}">
                <span @class(['size-2 rounded-full', 'bg-emerald-400 shadow-[0_0_8px] shadow-emerald-400/60' => $syncOk, 'bg-amber-400' => ! $syncOk])></span>
                {{ $lastSync ? 'Drive lido '.$lastSync->diffForHumans() : 'Drive ainda não lido' }}
            </a>

            <form method="POST" action="{{ route('logout') }}" class="border-t border-white/[0.06] p-4">
                @csrf
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-verde/15 font-semibold text-verde-lt">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-white">{{ auth()->user()->name }}</p>
                        <button class="text-xs text-mist/60 hover:text-white">Sair</button>
                    </div>
                </div>
            </form>
        </aside>

        <main class="min-w-0 flex-1">
            <div class="mx-auto max-w-[1280px] px-4 py-6 pb-32 sm:px-8 lg:py-10">
                @if ($errors->any())
                    <div class="rise mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                @if (session('notify'))
                    <x-whatsapp-notices title="Enviado! Agora avise no WhatsApp" dismissible class="mb-8" />
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts.base>
