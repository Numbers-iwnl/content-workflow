@props(['name', 'options', 'value' => null, 'multiple' => false, 'colors' => []])
{{-- Botões de escolha (no lugar de <select>): um clique em vez de três. --}}
<div {{ $attributes->class(['flex flex-wrap gap-2']) }}>
    @foreach ($options as $optionValue => $optionLabel)
        @php($checked = $multiple ? in_array($optionValue, (array) $value, true) : (string) $value === (string) $optionValue)
        <label class="cursor-pointer">
            <input type="{{ $multiple ? 'checkbox' : 'radio' }}" name="{{ $multiple ? $name.'[]' : $name }}" value="{{ $optionValue }}" class="peer sr-only" @checked($checked)>
            <span class="flex items-center gap-2 rounded-full border border-silver bg-white px-3.5 py-2 text-sm text-slate transition select-none hover:border-mist peer-checked:border-grafite peer-checked:bg-grafite peer-checked:text-white peer-focus-visible:ring-4 peer-focus-visible:ring-verde/20">
                @if (isset($colors[$optionValue]))
                    <span class="size-2.5 rounded-full ring-2 ring-white/70" style="background: {{ $colors[$optionValue] }}"></span>
                @endif
                {{ $optionLabel }}
            </span>
        </label>
    @endforeach
</div>
