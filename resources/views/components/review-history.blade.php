@props(['reviews', 'dark' => false])
{{-- O que Bruna e Carla decidiram e pediram, rodada a rodada. --}}
@if ($reviews->isNotEmpty())
    <ol {{ $attributes->class(['space-y-3']) }}>
        @foreach ($reviews as $review)
            @php($decision = $review->decision)
            <li @class(['rounded-xl p-4', 'card-dark' => $dark, 'border border-paper-2 bg-white' => ! $dark])>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span @class(['font-semibold', 'text-prata-lt' => $dark])>{{ $review->user->name }}</span>
                    <span @class([
                        'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        'bg-emerald-500/15 '.($dark ? 'text-emerald-300' : 'text-emerald-800') => $decision === \App\Enums\ReviewDecision::Aprovado,
                        'bg-amber-500/15 '.($dark ? 'text-amber-200' : 'text-amber-800') => $decision === \App\Enums\ReviewDecision::Ajuste,
                        'bg-red-500/15 '.($dark ? 'text-red-300' : 'text-red-700') => $decision === \App\Enums\ReviewDecision::Reprovado,
                    ])>{{ $decision->label() }}</span>
                    <span @class(['text-xs', 'text-prata-dk' => $dark, 'text-slate/60' => ! $dark])>{{ $review->created_at->format('d/m · H:i') }}@if ($review->round > 1) · rodada {{ $review->round }}@endif</span>
                </div>
                @if ($review->note)
                    <p @class(['mt-2 text-[15px] leading-relaxed whitespace-pre-line', 'text-prata-lt/90' => $dark, 'text-ink' => ! $dark])>{{ $review->note }}</p>
                @endif
                @if ($review->audio_path)
                    <audio src="{{ route('media.audio', $review) }}" controls preload="none" class="mt-3 h-10 w-full"></audio>
                @endif
                @if ($review->attachments->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($review->attachments as $attachment)
                            <a href="{{ route('media.attachment', $attachment) }}" target="_blank" class="block overflow-hidden rounded-lg ring-1 ring-black/5">
                                <img src="{{ route('media.attachment', $attachment) }}" alt="Anexo" class="size-20 object-cover">
                            </a>
                        @endforeach
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
@endif
