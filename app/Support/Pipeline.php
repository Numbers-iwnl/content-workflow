<?php

namespace App\Support;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * As abas da área da Ana (menu lateral). A Planilha tem tela própria;
 * as outras são grades de cartões.
 */
class Pipeline
{
    public const STAGES = [
        'entrada' => [
            'label' => 'Caixa de entrada',
            'hint' => 'Arquivos que chegaram do Drive. Confira área, tipo e CTA: com os três preenchidos, o conteúdo vai sozinho para a planilha.',
        ],
        'planilha' => [
            'label' => 'Planilha',
            'hint' => 'Todos os conteúdos depois da caixa de entrada. Marque os que precisam de aprovação e envie para a Bruna e a Carla.',
        ],
        'aprovacao' => [
            'label' => 'Em aprovação',
            'hint' => 'O que foi enviado para aprovação e ainda espera a opinião da Bruna ou da Carla.',
        ],
        'postados' => [
            'label' => 'Postados',
            'hint' => 'Conteúdos que já foram ao ar.',
        ],
        'arquivados' => [
            'label' => 'Arquivados',
            'hint' => 'Conteúdos que saíram do fluxo. Dá para trazer de volta para a caixa de entrada ou excluir de vez.',
        ],
    ];

    public static function stageOf(ContentStatus $status): string
    {
        return match ($status) {
            ContentStatus::Novo => 'entrada',
            ContentStatus::Postado => 'postados',
            ContentStatus::Arquivado => 'arquivados',
            default => 'planilha',
        };
    }

    /** Conteúdos da aba, já na ordem de trabalho. */
    public static function query(string $stage): Builder
    {
        return match ($stage) {
            'entrada' => Content::where('status', ContentStatus::Novo)->latest('drive_created_at')->latest('id'),
            // A planilha mostra também os postados (status "Postado"), como a planilha antiga.
            'planilha' => Content::whereIn('status', [...ContentStatus::planilha(), ContentStatus::Postado])->latest('drive_created_at')->latest('id'),
            'aprovacao' => Content::whereIn('status', ContentStatus::planilha())
                ->where('needs_approval', true)
                ->where(fn ($q) => User::approvers()->get()->each(fn (User $u) => $q->orWhere(fn ($q) => $q->awaiting($u))))
                ->orderBy('sent_for_review_at')->orderBy('id'),
            'postados' => Content::where('status', ContentStatus::Postado)->latest('posted_at')->latest('id'),
            default => Content::where('status', ContentStatus::Arquivado)->latest('updated_at')->latest('id'),
        };
    }

    /** @return Collection<string, int> */
    public static function counts(): Collection
    {
        return collect(self::STAGES)->map(fn ($stage, $key) => self::query($key)->reorder()->count());
    }
}
