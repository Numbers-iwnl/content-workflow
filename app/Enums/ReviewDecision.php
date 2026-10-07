<?php

namespace App\Enums;

enum ReviewDecision: string
{
    case Aprovado = 'aprovado';
    case Ajuste = 'ajuste';
    case Reprovado = 'reprovado';

    public function label(): string
    {
        return match ($this) {
            self::Aprovado => 'Aprovado',
            self::Ajuste => 'Pediu correção',
            self::Reprovado => 'Reprovado',
        };
    }

    /** Como aparece nas colunas da Bruna e da Carla na planilha. */
    public function short(): string
    {
        return match ($this) {
            self::Aprovado => 'Aprovado',
            self::Ajuste => 'Correção',
            self::Reprovado => 'Reprovado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Aprovado => 'bg-emerald-500/15 text-emerald-800',
            self::Ajuste => 'bg-amber-500/15 text-amber-800',
            self::Reprovado => 'bg-red-500/12 text-red-700',
        };
    }
}
