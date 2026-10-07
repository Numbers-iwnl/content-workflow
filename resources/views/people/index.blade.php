<x-layouts.workspace title="Pessoas e acessos">
    <header class="mb-8 max-w-2xl">
        <p class="eyebrow text-verde-dk">Ferramentas</p>
        <h1 class="title-serif mt-2 text-[44px] text-grafite sm:text-[56px]">Pessoas e acessos</h1>
        <p class="mt-3 text-[15px] leading-relaxed text-slate">Ninguém usa senha: cada pessoa entra pelo próprio link. Envie <b class="text-ink">no privado</b>, nunca no grupo, porque quem tem o link entra como aquela pessoa. Gerar um link novo desativa o anterior. Para a Bruna e a Carla, aqui também fica o aviso pronto para o WhatsApp.</p>
    </header>

    <x-whatsapp-notices class="mb-8 max-w-5xl" />

    @if ($link = session('link'))
        <div class="rise mb-8 max-w-3xl rounded-[var(--radius-card)] bg-carbon p-6 text-white" x-data="copy(@js($link['message']))">
            <p class="eyebrow text-verde">Link de {{ $link['user'] }} · aparece só agora</p>
            <p class="mt-3 rounded-xl bg-white/[0.06] p-3 font-mono text-xs break-all text-mist">{{ $link['url'] }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="https://wa.me/?text={{ rawurlencode($link['message']) }}" target="_blank" rel="noopener" class="btn-verde">Enviar pelo WhatsApp</a>
                <button type="button" class="btn-ghost" @click="copy" x-text="copied ? 'Copiado ✓' : 'Copiar mensagem'"></button>
            </div>
        </div>
    @endif

    <div class="grid max-w-5xl gap-8 lg:grid-cols-[1fr_340px]">
        <ul id="pessoas" class="card divide-y divide-paper-2 self-start">
            @foreach ($users as $user)
                <li class="flex flex-wrap items-center gap-4 p-4 sm:px-5">
                    <span @class(['flex size-11 items-center justify-center rounded-full text-base font-semibold', 'bg-verde/15 text-verde-dk' => $user->isApprover(), 'bg-grafite/[0.07] text-grafite' => ! $user->isApprover()])>{{ mb_substr($user->name, 0, 1) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-ink">{{ $user->name }}</p>
                        <p class="text-sm text-slate">
                            {{ $user->isApprover() ? 'Aprova conteúdos' : 'Equipe de conteúdo' }}
                        </p>
                    </div>
                    <span @class(['flex items-center gap-1.5 text-xs', 'text-emerald-700' => $user->login_token_hash, 'text-slate/60' => ! $user->login_token_hash])>
                        <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $user->login_token_hash, 'bg-mist' => ! $user->login_token_hash])></span>
                        {{ $user->login_token_hash ? 'link ativo' : 'sem link' }}
                    </span>
                    <form method="POST" action="{{ route('people.link', $user) }}"
                          @if ($user->login_token_hash) onsubmit="return confirm('Gerar um link novo para {{ $user->name }}? O link atual para de funcionar.')" @endif>
                        @csrf
                        <button class="btn-outline btn-sm">{{ $user->login_token_hash ? 'Novo link' : 'Gerar link' }}</button>
                    </form>

                </li>
            @endforeach
        </ul>

        <form method="POST" action="{{ route('people.store') }}" class="card space-y-5 self-start p-5" x-data="{ role: '{{ old('role', 'admin') }}' }">
            @csrf
            <h2 class="title-serif text-2xl text-grafite">Adicionar pessoa</h2>
            <div>
                <label class="label" for="name">Nome</label>
                <input id="name" name="name" class="field" value="{{ old('name') }}" required>
            </div>
            <div>
                <span class="label">Papel</span>
                <x-seg name="role" :options="['admin' => 'Equipe', 'aprovadora' => 'Aprova']" :value="old('role', 'admin')" @change="role = $event.target.value" />
            </div>
            <p x-show="role === 'aprovadora'" x-cloak class="text-xs text-slate">Ela ganha uma coluna própria na planilha e a própria fila de aprovação.</p>
            <button class="btn-grafite w-full">Adicionar</button>
        </form>
    </div>
</x-layouts.workspace>
