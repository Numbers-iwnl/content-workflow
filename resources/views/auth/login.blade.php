<x-layouts.base title="Entrar" theme="#0E100F" body-class="bg-carbon text-prata-lt">
    <div class="pointer-events-none fixed inset-0 bg-[radial-gradient(70%_60%_at_50%_0%,rgba(94,143,114,0.18),transparent)]"></div>
    <main class="relative mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-6 py-16 text-center">
        <p class="eyebrow text-verde">Estúdio de Conteúdo</p>
        <h1 class="title-serif mt-3 text-[56px] text-prata-lt">Conteúdos</h1>
        <div class="mx-auto my-8 h-px w-16 bg-gradient-to-r from-transparent via-verde to-transparent"></div>

        @if ($invalid ?? false)
            <p class="mb-6 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">Este link não funciona mais. Um link mais novo pode ter sido gerado.</p>
        @endif

        <p class="text-[17px] leading-relaxed text-prata">Para entrar, abra o seu <span class="text-prata-lt">link pessoal</span> de acesso.</p>
        <p class="mt-3 text-sm text-prata-dk">Não tem um, ou perdeu? Peça para a Ana gerar um novo.</p>
    </main>
</x-layouts.base>
