<?php

namespace App\Enums;

enum Account: string
{
    case Principal = 'principal';
    case MarcaB = 'marca_b';
    case Podcast = 'podcast';

    public function label(): string
    {
        return match ($this) {
            self::Principal => 'Perfil principal',
            self::MarcaB => 'Marca B',
            self::Podcast => 'Podcast',
        };
    }

    /** Sigla do "avatar" da conta. */
    public function initials(): string
    {
        return match ($this) {
            self::Principal => 'PP',
            self::MarcaB => 'MB',
            self::Podcast => 'P',
        };
    }

    /** Cor da conta (avatar, pontos nas listas). */
    public function color(): string
    {
        return match ($this) {
            self::Principal => '#5E8F72',     // verde principal
            self::MarcaB => '#137CCB', // azul
            self::Podcast => '#1C3458', // navy
        };
    }
}
