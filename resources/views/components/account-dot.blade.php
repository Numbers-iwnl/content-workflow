@props(['account', 'size' => 'sm'])
{{-- "Avatar" da conta do Instagram. --}}
@if ($account)
    <span {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center rounded-full font-display font-bold text-white',
        'size-5 text-[8px]' => $size === 'xs',
        'size-7 text-[10px]' => $size === 'sm',
        'size-10 text-[13px]' => $size === 'md',
    ]) }} style="background: {{ $account->color() }}" title="{{ $account->label() }}">{{ $account->initials() }}</span>
@else
    <span {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center rounded-full border border-dashed border-mist text-mist',
        'size-5 text-[9px]' => $size === 'xs',
        'size-7 text-[11px]' => $size === 'sm',
        'size-10 text-sm' => $size === 'md',
    ]) }} title="Conta não definida">?</span>
@endif
