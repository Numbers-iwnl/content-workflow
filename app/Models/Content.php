<?php

namespace App\Models;

use App\Enums\Account;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\Cta;
use App\Enums\ReviewDecision;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

#[Guarded(['id'])]
class Content extends Model
{
    protected $attributes = [
        'status' => 'novo',
        'round' => 1,
        'needs_approval' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'account' => Account::class,
            'type' => ContentType::class,
            'cta' => Cta::class,
            'collab_accounts' => 'array',
            'needs_approval' => 'boolean',
            'drive_created_at' => 'datetime',
            'sent_for_review_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class)->orderBy('position')->orderBy('name');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->latest('id');
    }

    /** Último pedido de correção (para mostrar "o que corrigir"). */
    public function latestChangeRequest(): HasOne
    {
        return $this->hasOne(Review::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('decision', ReviewDecision::Ajuste));
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContentEvent::class)->latest('id');
    }

    public function suggestedCorrectionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'suggested_correction_of_id');
    }

    public function watchedFolder(): BelongsTo
    {
        return $this->belongsTo(WatchedFolder::class);
    }

    /**
     * Os "filtros" que a Ana ajusta na caixa de entrada. Com eles
     * preenchidos, o conteúdo vai sozinho para a planilha.
     */
    public function missingForPlanilha(): array
    {
        return array_keys(array_filter([
            'área' => $this->account === null,
            'tipo' => $this->type === null,
            'CTA' => $this->cta === null,
        ]));
    }

    /**
     * A decisão mais recente de cada aprovadora (coluna dela na planilha).
     *
     * @return Collection<int, Review> indexado por user_id
     */
    public function decisions(): Collection
    {
        return $this->reviews
            ->filter(fn (Review $r) => $r->id > (int) $this->reviews_reset_after_id)
            ->sortByDesc('id')
            ->unique('user_id')
            ->keyBy('user_id');
    }

    /** Alguém reprovou (e a reprovação ainda vale). */
    public function isRejected(): bool
    {
        return $this->decisions()->contains(fn (Review $r) => $r->decision === ReviewDecision::Reprovado);
    }

    /** Alguém pediu correção e ela ainda não foi feita. */
    public function hasPendingCorrection(): bool
    {
        return $this->status !== ContentStatus::Corrigido
            && $this->decisions()->contains(fn (Review $r) => $r->decision === ReviewDecision::Ajuste);
    }

    /** Mesma regra do scopeAwaiting, para conteúdos já carregados. */
    public function isAwaiting(User $reviewer): bool
    {
        if (! $this->status->reviewable() || ! $this->needs_approval) {
            return false;
        }

        $mine = $this->decisions()->get($reviewer->id);

        return ! $mine || ($mine->decision === ReviewDecision::Ajuste && $this->status === ContentStatus::Corrigido);
    }

    /**
     * Conteúdos que esperam a opinião desta pessoa: ela ainda não decidiu,
     * ou pediu correção e a correção já chegou.
     */
    public function scopeAwaiting(Builder $query, User $reviewer): void
    {
        // Só contam as opiniões depois do último "reverter" (reviews_reset_after_id).
        $latestDecision = '(select decision from reviews where reviews.content_id = contents.id and reviews.user_id = ? and reviews.id > coalesce(contents.reviews_reset_after_id, 0) order by reviews.id desc limit 1)';

        $query->whereIn('status', ContentStatus::planilha())
            ->where('needs_approval', true)
            ->where(fn ($q) => $q
                ->whereRaw("{$latestDecision} is null", [$reviewer->id])
                ->orWhere(fn ($q) => $q
                    ->where('status', ContentStatus::Corrigido)
                    ->whereRaw("{$latestDecision} = ?", [$reviewer->id, ReviewDecision::Ajuste->value])));
    }
}
