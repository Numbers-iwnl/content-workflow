<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';           // Ana, Eduardo: catálogo, legendas, calendário
    case Aprovadora = 'aprovadora'; // Bruna, Carla
}
