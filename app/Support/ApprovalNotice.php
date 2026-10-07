<?php

namespace App\Support;

use App\Models\Content;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Mensagens prontas para avisar a Bruna e a Carla no WhatsApp. Cada uma leva
 * o link pessoal de quem recebe: tocar nele entra no sistema em qualquer
 * aparelho e já abre a lista de aprovação.
 */
class ApprovalNotice
{
    /**
     * @return Collection<int, array{user: User, total: int, link: string, personal: bool, message: string}>
     */
    public static function perApprover(): Collection
    {
        return User::approvers()->get()->map(function (User $user) {
            $total = Content::awaiting($user)->count();
            $personal = $user->loginUrl();
            $link = $personal ?? route('review.index');
            $plural = $total === 1 ? 'conteúdo esperando' : 'conteúdos esperando';

            return [
                'user' => $user,
                'total' => $total,
                'link' => $link,
                'personal' => $personal !== null,
                'message' => "Oi, {$user->name}! Tem {$total} {$plural} a sua aprovação.\n\n"
                    .($personal
                        ? "Seu acesso (é só tocar, funciona em qualquer celular ou computador):\n{$link}"
                        : "Para aprovar:\n{$link}"),
            ];
        });
    }

    /** Uma mensagem para o grupo, com o link de cada uma. */
    public static function group(Collection $perApprover): ?string
    {
        $waiting = $perApprover->where('total', '>', 0);

        if ($waiting->isEmpty()) {
            return null;
        }

        $lines = $waiting->map(fn ($n) => "• {$n['user']->name} ({$n['total']}): {$n['link']}");

        return "Oi! Tem conteúdo esperando aprovação. Cada uma toca no próprio link:\n\n".$lines->join("\n");
    }
}
