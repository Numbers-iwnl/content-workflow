<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Enums\DriveFileState;
use App\Enums\ReviewDecision;
use App\Models\Content;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Todas as mudanças de status passam por aqui, para o histórico ficar
 * completo e as regras ficarem num lugar só:
 *
 *  - Caixa de entrada → Planilha: quando a Ana preenche área, tipo e CTA.
 *  - Só vai para a Bruna e a Carla o que a Ana enviar para aprovação
 *    (vídeo de tráfego, por exemplo, pode nem passar por elas).
 *  - Bruna e Carla opinam de forma independente, em qualquer ordem. A
 *    opinião de cada uma é uma coluna na planilha; nenhuma bloqueia a outra,
 *    e a Ana pode agendar sem esperar as duas.
 *  - Pedido de correção: quando o arquivo é trocado no Drive (ou a Ana
 *    marca), vira "Corrigido" e volta para quem pediu.
 */
class ContentWorkflow
{
    /** Leva para a planilha se os filtros estiverem preenchidos. */
    public function moveToPlanilha(Content $content, ?User $by): bool
    {
        if ($content->status !== ContentStatus::Novo || $content->missingForPlanilha()) {
            return false;
        }

        $content->sent_for_review_at = now();
        $content->suggested_correction_of_id = null;
        $this->transition($content, ContentStatus::Pendente, $by, 'enviado_para_planilha');

        return true;
    }

    /** A Ana escolhe o que precisa passar pela Bruna e pela Carla. */
    public function requestApproval(Content $content, User $by): void
    {
        $this->assertStatus($content, ContentStatus::planilha());

        if (! $content->needs_approval) {
            $content->forceFill(['needs_approval' => true, 'sent_for_review_at' => now()])->save();
            $this->log($content, 'enviado_para_aprovacao', $by);
        }
    }

    public function cancelApproval(Content $content, User $by): void
    {
        if ($content->needs_approval) {
            $content->forceFill(['needs_approval' => false])->save();
            $this->log($content, 'retirado_da_aprovacao', $by);
        }
    }

    /**
     * Desfaz uma reprovação: as opiniões até aqui ficam no histórico, mas
     * deixam de valer, e o conteúdo volta para as duas aprovarem do zero.
     */
    public function revertRejection(Content $content, User $by): void
    {
        $content->loadMissing('reviews');

        if (! $content->isRejected()) {
            throw new InvalidArgumentException('Este conteúdo não está reprovado.');
        }

        $content->forceFill([
            'reviews_reset_after_id' => $content->reviews->max('id'),
            'needs_approval' => true,
            'scheduled_for' => null,
            'sent_for_review_at' => now(),
        ]);
        $content->unsetRelation('reviews');

        $this->transition($content, $content->status === ContentStatus::Postado ? ContentStatus::Postado : ContentStatus::Pendente, $by, 'reprovacao_revertida');
    }

    /**
     * @param  array<int, array{path: string, mime_type: ?string}>  $attachments
     */
    public function review(Content $content, User $reviewer, ReviewDecision $decision, ?string $note = null, ?string $audioPath = null, array $attachments = []): Review
    {
        if (! $reviewer->isApprover()) {
            throw new InvalidArgumentException('Só a Bruna e a Carla aprovam conteúdos.');
        }

        if (! $content->status->reviewable() || ! $content->needs_approval) {
            throw new InvalidArgumentException('Este conteúdo não está em aprovação.');
        }

        if ($decision !== ReviewDecision::Aprovado && blank($note) && ! $audioPath && ! $attachments) {
            throw new InvalidArgumentException('Explique o motivo: texto, áudio ou imagem.');
        }

        return DB::transaction(function () use ($content, $reviewer, $decision, $note, $audioPath, $attachments) {
            $review = $content->reviews()->create([
                'user_id' => $reviewer->id,
                'round' => $content->round,
                'decision' => $decision,
                'note' => $note,
                'audio_path' => $audioPath,
            ]);
            $review->attachments()->createMany($attachments);

            $data = ['revisora' => $reviewer->name, 'review_id' => $review->id];

            if ($decision === ReviewDecision::Ajuste && $content->status !== ContentStatus::Pendente) {
                // Pediu correção num agendado ou corrigido: volta a ficar pendente.
                $content->scheduled_for = null;
                $this->transition($content, ContentStatus::Pendente, $reviewer, 'correcao_pedida', $data);
            } else {
                $this->log($content, match ($decision) {
                    ReviewDecision::Aprovado => 'aprovado',
                    ReviewDecision::Ajuste => 'correcao_pedida',
                    ReviewDecision::Reprovado => 'reprovado',
                }, $reviewer, data: $data);
            }

            return $review;
        });
    }

    public function markCorrected(Content $content, ?User $by, array $data = []): void
    {
        $content->loadMissing('reviews');

        if (! $content->status->inPlanilha() || ! $content->hasPendingCorrection()) {
            throw new InvalidArgumentException('Não há correção pendente neste conteúdo.');
        }

        $content->round++;
        $content->scheduled_for = null;
        $this->transition($content, ContentStatus::Corrigido, $by, 'corrigido', $data);
    }

    /**
     * Chamado pela sincronização quando o arquivo de um conteúdo muda no
     * Drive. Se havia correção pedida, a nova versão é a correção.
     */
    public function fileChanged(Content $content, array $data): void
    {
        $content->load('reviews');

        if ($content->status->inPlanilha() && $content->hasPendingCorrection()) {
            $this->markCorrected($content, null, $data + ['automatico' => true]);

            return;
        }

        $this->log($content, 'arquivo_atualizado', null, data: $data);
    }

    /**
     * A Ana confirma que um conteúdo novo é, na verdade, a correção de outro:
     * o arquivo novo substitui o antigo e o conteúdo duplicado some.
     */
    public function linkAsCorrection(Content $newContent, Content $original, User $by): void
    {
        DB::transaction(function () use ($newContent, $original, $by) {
            $original->files()->update([
                'content_id' => null,
                'state' => DriveFileState::Removed,
                'ignore_reason' => "substituído pela correção (conteúdo #{$newContent->id})",
            ]);
            $newContent->files()->update(['content_id' => $original->id]);

            $this->markCorrected($original, $by, ['novo_arquivo' => $newContent->title]);
            $newContent->delete();
        });
    }

    public function schedule(Content $content, CarbonInterface $when, User $by): void
    {
        $this->assertStatus($content, ContentStatus::planilha());
        $content->scheduled_for = $when;
        $this->transition($content, ContentStatus::Agendado, $by, 'agendado', ['para' => $when->toDateTimeString()]);
    }

    public function unschedule(Content $content, User $by): void
    {
        $this->assertStatus($content, [ContentStatus::Agendado]);
        $content->scheduled_for = null;
        $this->transition($content, ContentStatus::Pendente, $by, 'desagendado');
    }

    public function markPosted(Content $content, User $by): void
    {
        $this->assertStatus($content, ContentStatus::planilha());
        $content->posted_at = now();
        $this->transition($content, ContentStatus::Postado, $by, 'postado');
    }

    public function archive(Content $content, ?User $by, ?string $reason = null): void
    {
        if ($content->status === ContentStatus::Arquivado) {
            return;
        }

        $this->transition($content, ContentStatus::Arquivado, $by, 'arquivado', array_filter(['motivo' => $reason]));
    }

    public function restore(Content $content, User $by): void
    {
        $this->assertStatus($content, [ContentStatus::Arquivado, ContentStatus::Postado]);
        $content->scheduled_for = null;
        $this->transition($content, ContentStatus::Novo, $by, 'restaurado');
    }

    /**
     * Exclui do sistema (só arquivados). O arquivo no Drive não é tocado e
     * fica marcado para não voltar a entrar na caixa de entrada.
     */
    public function destroy(Content $content): void
    {
        $this->assertStatus($content, [ContentStatus::Arquivado]);
        $content->load(['files', 'reviews.attachments']);

        $media = Storage::disk(config('conteudo.media_disk'));
        $private = Storage::disk('local');

        DB::transaction(function () use ($content, $media, $private) {
            foreach ($content->files as $file) {
                $media->delete(array_filter([$file->cache_path, $file->thumbnail_path]));
                $file->forceFill([
                    'content_id' => null,
                    'state' => DriveFileState::Ignored,
                    'ignore_reason' => 'excluído definitivamente do sistema',
                    'cache_path' => null,
                    'cached_md5' => null,
                    'thumbnail_path' => null,
                ])->save();
            }

            foreach ($content->reviews as $review) {
                $private->delete(array_filter([$review->audio_path, ...$review->attachments->pluck('path')]));
            }

            $content->delete();
        });
    }

    public function log(Content $content, string $type, ?User $by, ?ContentStatus $from = null, ?ContentStatus $to = null, array $data = []): void
    {
        $content->events()->create([
            'user_id' => $by?->id,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'data' => $data ?: null,
        ]);
    }

    private function transition(Content $content, ContentStatus $to, ?User $by, string $type, array $data = []): void
    {
        $from = $content->getOriginal('status');
        $content->status = $to;
        $content->save();

        $this->log($content, $type, $by, $from instanceof ContentStatus ? $from : ContentStatus::tryFrom((string) $from), $to, $data);
    }

    private function assertStatus(Content $content, array $allowed): void
    {
        if (! in_array($content->status, $allowed, true)) {
            throw new InvalidArgumentException("Ação não permitida com o status \"{$content->status->label()}\".");
        }
    }
}
