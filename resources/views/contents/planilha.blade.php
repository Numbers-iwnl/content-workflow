@php
    $columns = [
        ['key' => 'created', 'label' => 'Criado em'],
        ['key' => 'account', 'label' => 'Área'],
        ['key' => 'type', 'label' => 'Tipo'],
        ['key' => 'cta', 'label' => 'CTA'],
        ['key' => 'title', 'label' => 'Título / assunto'],
        ['key' => 'caption', 'label' => 'Legenda'],
        ['key' => 'producer', 'label' => 'Produção'],
        ...$approvers->map(fn ($a) => ['key' => 'u'.$a->id, 'label' => $a->name])->all(),
        ['key' => 'status', 'label' => 'Status'],
    ];
@endphp

<x-layouts.workspace title="Planilha">
    <div x-data="sheet(@js($rows), @js($columns))" @keydown.escape.window="open = null">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div class="max-w-2xl">
                <p class="eyebrow text-verde-dk">Fluxo de conteúdo</p>
                <h1 class="title-serif mt-2 text-[44px] text-grafite sm:text-[56px]">Planilha</h1>
                <p class="mt-3 text-[15px] leading-relaxed text-slate">Clique no nome da coluna para filtrar ou ordenar; arraste para mudar a ordem. O título abre o conteúdo. Marque os conteúdos que precisam de aprovação e envie para a Bruna e a Carla.</p>
            </div>
        </header>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <label class="relative min-w-56 flex-1 sm:max-w-sm">
                <svg class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" x-model.debounce.150ms="search" placeholder="Buscar pelo título" class="field py-2.5 pl-10">
            </label>
            <p class="text-sm text-slate tabular-nums"><span x-text="visible.length"></span> de <span x-text="rows.length"></span> conteúdos</p>
            <div class="ml-auto flex gap-2">
                <button type="button" class="btn-quiet btn-sm" x-show="anyFilter" x-cloak @click="clearAll()">Limpar filtros</button>
                <button type="button" class="btn-quiet btn-sm" @click="resetColumns()">Ordem original das colunas</button>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full min-w-[880px] border-collapse text-left text-[13px]">
                <thead class="sticky top-0 z-10 bg-white">
                    <tr class="border-b border-paper-2">
                        <th class="w-10 py-3 pl-4">
                            <input type="checkbox" class="size-4 rounded accent-verde" :checked="allSelected" @change="toggleAll()" title="Marcar todos">
                        </th>
                        <template x-for="key in order" :key="key">
                            <th class="relative px-2 py-3 align-bottom font-normal select-none"
                                draggable="true"
                                @dragstart="dragging = key" @dragend="dragging = null"
                                @dragover.prevent @drop.prevent="drop(key)"
                                :class="dragging === key && 'opacity-40'">
                                <button type="button" class="eyebrow flex items-center gap-1 text-left text-[10px] leading-tight tracking-[0.12em] text-slate hover:text-ink"
                                        :class="(filtered(key) || sort.key === key) && '!text-verde-dk'"
                                        @click="open = open === key ? null : key">
                                    <span x-text="column(key).label"></span>
                                    <span x-show="sort.key === key" x-text="sort.dir === 'asc' ? '↑' : '↓'"></span>
                                    <svg x-show="filtered(key)" class="size-3" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4h14l-5.5 6.5V16l-3 1.5v-7L3 4Z"/></svg>
                                    <svg x-show="! filtered(key)" class="size-3 opacity-40" viewBox="0 0 20 20" fill="currentColor"><path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4Z"/></svg>
                                </button>

                                {{-- Filtro e ordenação da coluna --}}
                                <div x-show="open === key" x-cloak x-transition.opacity.duration.150ms @click.outside="open = null"
                                     class="absolute top-full left-2 z-20 mt-1 w-64 rounded-xl border border-paper-2 bg-white p-2 text-sm normal-case shadow-2xl shadow-grafite/15">
                                    <div class="grid grid-cols-2 gap-1 border-b border-paper-2 pb-2">
                                        <button type="button" class="rounded-lg px-2 py-1.5 text-left hover:bg-paper" @click="sortBy(key, 'asc')">A → Z ↑</button>
                                        <button type="button" class="rounded-lg px-2 py-1.5 text-left hover:bg-paper" @click="sortBy(key, 'desc')">Z → A ↓</button>
                                    </div>
                                    <template x-if="key !== 'title' && key !== 'created'">
                                        <div class="pt-2">
                                            <div class="mb-1 flex justify-between px-1 text-xs">
                                                <span class="text-slate">Mostrar</span>
                                                <button type="button" class="text-verde-dk hover:underline" @click="clearFilter(key)">todos</button>
                                            </div>
                                            <ul class="max-h-64 overflow-y-auto">
                                                <template x-for="[value, count] in options(key)" :key="value">
                                                    <li class="group flex items-center gap-2 rounded-lg px-1 hover:bg-paper">
                                                        <label class="flex flex-1 cursor-pointer items-center gap-2 py-1.5">
                                                            <input type="checkbox" class="size-4 rounded accent-verde" :checked="isShown(key, value)" @change="toggle(key, value)">
                                                            <span class="flex-1 truncate" x-text="value"></span>
                                                            <span class="text-xs text-slate/60 tabular-nums" x-text="count"></span>
                                                        </label>
                                                        <button type="button" class="hidden text-xs text-verde-dk group-hover:block" @click="only(key, value)">só</button>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, index) in visible" :key="row.id">
                        <tr class="border-b border-paper-2 last:border-0 transition-colors hover:bg-verde/[0.07]"
                            :class="selected.includes(row.id) ? 'bg-verde/[0.09]' : (index % 2 ? 'bg-paper/80' : 'bg-white')">
                            <td class="py-2.5 pl-4">
                                <input type="checkbox" class="size-4 rounded accent-verde" :value="row.id" x-model.number="selected">
                            </td>
                            <template x-for="key in order" :key="key">
                                <td class="px-2 py-2.5 align-middle" :class="key === 'title' && 'min-w-44 max-w-64'" x-html="cell(row, key)"></td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="! visible.length">
                        <td :colspan="order.length + 1" class="px-3 py-16 text-center text-slate">
                            <template x-if="rows.length">
                                <span>Nenhum conteúdo com esses filtros. <button type="button" class="text-verde-dk underline" @click="clearAll()">Limpar filtros</button></span>
                            </template>
                            <template x-if="! rows.length">
                                <span>A planilha está vazia. Os conteúdos aparecem aqui depois de passar pela caixa de entrada.</span>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('contents.approval-many') }}" x-show="selected.length" x-cloak x-transition.opacity
              class="fixed inset-x-0 bottom-0 z-30 p-4 lg:left-64">
            @csrf
            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
            <div class="mx-auto flex max-w-2xl flex-wrap items-center justify-between gap-3 rounded-2xl bg-carbon p-3 pl-5 text-white shadow-2xl shadow-carbon/40">
                <p class="text-sm"><b class="text-verde-lt" x-text="selected.length"></b> <span x-text="selected.length === 1 ? 'marcado' : 'marcados'"></span>
                    · <button type="button" class="text-prata underline-offset-2 hover:underline" @click="selected = []">desmarcar</button></p>
                <div class="flex gap-2">
                    <button name="acao" value="retirar" class="btn-ghost btn-sm">Tirar da aprovação</button>
                    <button name="acao" value="enviar" class="btn-verde btn-sm">Enviar para a Bruna e a Carla</button>
                </div>
            </div>
        </form>
    </div>
</x-layouts.workspace>
