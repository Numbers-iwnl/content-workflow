<?php

namespace App\Enums;

/**
 * Caixa de entrada → Planilha → Postados, e Arquivados à parte.
 * Na Planilha o status é simples, como na planilha antiga da equipe:
 * vazio (pendente), corrigido, agendado. As decisões da Bruna e da Carla
 * ficam nas colunas delas (reviews), não no status.
 */
enum ContentStatus: string
{
    case Novo = 'novo';             // caixa de entrada: Ana ainda não conferiu
    case Pendente = 'pendente';     // na planilha, status "vazio"
    case Corrigido = 'corrigido';   // um ajuste pedido já foi feito
    case Agendado = 'agendado';
    case Postado = 'postado';
    case Arquivado = 'arquivado';   // reprovado, ignorado ou fora de uso

    public function label(): string
    {
        return match ($this) {
            self::Novo => 'Novo',
            self::Pendente => '—',
            self::Corrigido => 'Corrigido',
            self::Agendado => 'Agendado',
            self::Postado => 'Postado',
            self::Arquivado => 'Arquivado',
        };
    }

    /** Classes do Tailwind para o selo de status. */
    public function color(): string
    {
        return match ($this) {
            self::Novo => 'bg-verde/12 text-verde-dk',
            self::Pendente => 'bg-paper-2 text-slate',
            self::Corrigido => 'bg-cyan-500/15 text-cyan-800',
            self::Agendado => 'bg-verde/15 text-verde-dk',
            self::Postado => 'bg-carbon text-white',
            self::Arquivado => 'bg-paper-2 text-slate',
        };
    }

    /** Está na planilha (já passou pela caixa de entrada e não foi postado). */
    public function inPlanilha(): bool
    {
        return in_array($this, [self::Pendente, self::Corrigido, self::Agendado], true);
    }

    /** Bruna e Carla ainda podem dar a opinião delas. */
    public function reviewable(): bool
    {
        return $this->inPlanilha();
    }

    /** Estados em que a prévia (cópia do arquivo no servidor) ainda é necessária. */
    public function needsMedia(): bool
    {
        return ! in_array($this, [self::Postado, self::Arquivado], true);
    }

    /** @return array<self> */
    public static function planilha(): array
    {
        return [self::Pendente, self::Corrigido, self::Agendado];
    }
}
