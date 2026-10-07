<?php

namespace App\Enums;

enum ContentType: string
{
    case Reel = 'reel';
    case Estatico = 'estatico';
    case Carrossel = 'carrossel';
    case Story = 'story';

    public function label(): string
    {
        return match ($this) {
            self::Reel => 'Reel',
            self::Estatico => 'Card estático',
            self::Carrossel => 'Carrossel',
            self::Story => 'Story',
        };
    }
}
