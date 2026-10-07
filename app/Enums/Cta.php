<?php

namespace App\Enums;

enum Cta: string
{
    case Organico = 'organico';
    case Trafego = 'trafego';

    public function label(): string
    {
        return match ($this) {
            self::Organico => 'Orgânico',
            self::Trafego => 'Tráfego',
        };
    }
}
