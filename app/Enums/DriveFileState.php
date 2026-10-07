<?php

namespace App\Enums;

enum DriveFileState: string
{
    case Baseline = 'baseline'; // já existia quando a pasta passou a ser monitorada
    case Ignored = 'ignored';   // barrado pelas regras
    case Imported = 'imported'; // virou (parte de) um conteúdo
    case Removed = 'removed';   // apagado ou movido para fora das pastas monitoradas
}
