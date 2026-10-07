@props(['title' => 'Avisar no WhatsApp', 'dismissible' => false])
{{-- Avisos prontos para a Bruna e a Carla, cada um com o link pessoal de quem recebe. --}}
@php
    $notices = \App\Support\ApprovalNotice::perApprover();
    $group = \App\Support\ApprovalNotice::group($notices);
    $missing = $notices->where('personal', false);
@endphp

<section {{ $attributes->class(['rise rounded-[var(--radius-card)] border border-verde/30 bg-verde/[0.06] p-5']) }} x-data="{ open: true }" x-show="open">
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <p class="eyebrow text-verde-dk">{{ $title }}</p>
            <p class="mt-1 text-sm text-slate">Cada mensagem já leva o link pessoal de quem recebe: é só tocar para entrar, em qualquer celular ou computador.</p>
        </div>
        @if ($dismissible)
            <button type="button" class="rounded-lg p-1.5 text-slate hover:bg-white" @click="open = false" aria-label="Fechar">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 5.3a1 1 0 0 0-1.4 1.4L8.6 10l-3.7 3.3a1 1 0 1 0 1.4 1.4L10 11.4l3.7 3.3a1 1 0 0 0 1.4-1.4L11.4 10l3.7-3.3a1 1 0 0 0-1.4-1.4L10 8.6 6.3 5.3Z"/></svg>
            </button>
        @endif
    </div>

    @if ($missing->isNotEmpty())
        <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
            A mensagem de {{ $missing->pluck('user.name')->join(' e ') }} ainda vai sem o link pessoal.
            Gere um <a href="{{ route('people.index') }}#pessoas" class="font-semibold underline">novo link em Pessoas e acessos</a> (só uma vez) e ele passa a ir junto em todo aviso.
        </p>
    @endif

    <div @class(['grid gap-3', 'md:grid-cols-3' => $group, 'md:grid-cols-2' => ! $group])>
        @foreach ($notices as $notice)
            <div class="flex flex-col rounded-xl bg-white p-4 ring-1 ring-paper-2" x-data="copy(@js($notice['message']))">
                <p class="font-medium text-ink">Só para {{ $notice['user']->name }}</p>
                <p class="mt-0.5 text-sm text-slate">
                    {{ $notice['total'] ? ($notice['total'] === 1 ? '1 conteúdo esperando' : "{$notice['total']} conteúdos esperando") : 'Nada esperando agora' }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2 pt-1 md:mt-auto">
                    <a href="https://wa.me/?text={{ rawurlencode($notice['message']) }}" target="_blank" rel="noopener" class="btn-verde btn-sm">WhatsApp</a>
                    <button type="button" class="btn-quiet btn-sm" @click="copy" x-text="copied ? 'Copiado ✓' : 'Copiar'"></button>
                </div>
            </div>
        @endforeach

        @if ($group)
            <div class="flex flex-col rounded-xl bg-white p-4 ring-1 ring-paper-2" x-data="copy(@js($group))">
                <p class="font-medium text-ink">Para o grupo</p>
                <p class="mt-0.5 text-sm text-slate">Uma mensagem com o link de cada uma.</p>
                <div class="mt-3 flex flex-wrap gap-2 pt-1 md:mt-auto">
                    <a href="https://wa.me/?text={{ rawurlencode($group) }}" target="_blank" rel="noopener" class="btn-grafite btn-sm">WhatsApp</a>
                    <button type="button" class="btn-quiet btn-sm" @click="copy" x-text="copied ? 'Copiado ✓' : 'Copiar'"></button>
                </div>
            </div>
        @endif
    </div>
</section>
