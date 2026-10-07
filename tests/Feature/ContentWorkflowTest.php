<?php

namespace Tests\Feature;

use App\Enums\Account;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\Cta;
use App\Enums\ReviewDecision;
use App\Enums\Role;
use App\Models\Content;
use App\Models\User;
use App\Services\ContentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ContentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ContentWorkflow $workflow;

    private User $ana;

    private User $bruna;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = app(ContentWorkflow::class);
        $this->ana = User::create(['name' => 'Ana', 'role' => Role::Admin]);
        $this->bruna = User::create(['name' => 'Bruna', 'role' => Role::Aprovadora, 'review_order' => 1]);
        $this->carla = User::create(['name' => 'Carla', 'role' => Role::Aprovadora, 'review_order' => 2]);
    }

    private function content(array $overrides = []): Content
    {
        return Content::create($overrides + [
            'title' => 'Reels - Teste',
            'status' => ContentStatus::Novo,
            'account' => Account::Principal,
            'type' => ContentType::Reel,
            'cta' => Cta::Organico,
        ]);
    }

    private function inPlanilha(array $overrides = []): Content
    {
        $content = $this->content($overrides);
        $this->workflow->moveToPlanilha($content, $this->ana);
        $this->workflow->requestApproval($content, $this->ana);

        return $content;
    }

    public function test_only_what_ana_sends_goes_to_approval(): void
    {
        $content = $this->content(['cta' => Cta::Trafego]);
        $this->workflow->moveToPlanilha($content, $this->ana);

        $this->assertFalse(Content::awaiting($this->bruna)->whereKey($content->id)->exists(), 'vídeo de tráfego não precisa de aprovação');

        $this->workflow->requestApproval($content, $this->ana);
        $this->assertTrue(Content::awaiting($this->bruna)->whereKey($content->id)->exists());

        $this->workflow->cancelApproval($content, $this->ana);
        $this->assertFalse(Content::awaiting($this->carla)->whereKey($content->id)->exists());
    }

    public function test_cannot_review_what_was_not_sent(): void
    {
        $content = $this->content();
        $this->workflow->moveToPlanilha($content, $this->ana);

        $this->expectExceptionMessage('não está em aprovação');
        $this->workflow->review($content, $this->bruna, ReviewDecision::Aprovado);
    }

    public function test_reverting_a_rejection_starts_approval_over(): void
    {
        $content = $this->inPlanilha();
        $this->workflow->review($content, $this->bruna, ReviewDecision::Aprovado);
        $this->workflow->review($content, $this->carla, ReviewDecision::Reprovado, 'Fora do tom');
        $this->assertTrue($content->fresh()->isRejected());

        $this->workflow->revertRejection($content->fresh(), $this->ana);

        $content = $content->fresh();
        $this->assertFalse($content->isRejected());
        $this->assertTrue($content->decisions()->isEmpty(), 'as duas colunas voltam a ficar vazias');
        $this->assertTrue(Content::awaiting($this->bruna)->whereKey($content->id)->exists());
        $this->assertTrue(Content::awaiting($this->carla)->whereKey($content->id)->exists());
        $this->assertSame(2, $content->reviews()->count(), 'o histórico continua guardado');
    }

    public function test_cannot_revert_what_was_not_rejected(): void
    {
        $content = $this->inPlanilha();

        $this->expectException(InvalidArgumentException::class);
        $this->workflow->revertRejection($content, $this->ana);
    }

    public function test_goes_to_planilha_once_area_type_and_cta_are_filled(): void
    {
        $incomplete = $this->content(['cta' => null]);
        $this->assertFalse($this->workflow->moveToPlanilha($incomplete, $this->ana));
        $this->assertSame(['CTA'], $incomplete->missingForPlanilha());

        // Legenda não é obrigatória: na planilha vira a coluna "tem legenda?".
        $complete = $this->content(['caption' => null]);
        $this->assertTrue($this->workflow->moveToPlanilha($complete, $this->ana));
        $this->assertSame(ContentStatus::Pendente, $complete->status);
    }

    public function test_bruna_and_carla_review_independently_in_any_order(): void
    {
        $content = $this->inPlanilha();

        $this->assertTrue(Content::awaiting($this->carla)->whereKey($content->id)->exists());
        $this->assertTrue(Content::awaiting($this->bruna)->whereKey($content->id)->exists());

        // A Carla decide primeiro: tudo bem.
        $this->workflow->review($content, $this->carla, ReviewDecision::Aprovado);

        $this->assertFalse(Content::awaiting($this->carla)->whereKey($content->id)->exists());
        $this->assertTrue(Content::awaiting($this->bruna)->whereKey($content->id)->exists());
        $this->assertSame('Aprovado', $content->fresh()->decisions()->get($this->carla->id)->decision->short());
        $this->assertNull($content->fresh()->decisions()->get($this->bruna->id));
    }

    public function test_can_schedule_and_post_without_both_approvals(): void
    {
        $content = $this->inPlanilha();
        $this->workflow->review($content, $this->bruna, ReviewDecision::Aprovado);

        $this->workflow->schedule($content, now()->addDay()->setTime(12, 0), $this->ana);
        $this->assertSame(ContentStatus::Agendado, $content->status);

        $this->workflow->markPosted($content, $this->ana);
        $this->assertSame(ContentStatus::Postado, $content->status);
    }

    public function test_correction_returns_to_whoever_asked(): void
    {
        $content = $this->inPlanilha();
        $this->workflow->review($content, $this->bruna, ReviewDecision::Aprovado);
        $this->workflow->review($content, $this->carla, ReviewDecision::Ajuste, 'Trocar a capa');

        $this->assertFalse(Content::awaiting($this->carla)->whereKey($content->id)->exists(), 'enquanto não corrige, não volta para a Carla');

        $this->workflow->markCorrected($content, $this->ana);

        $this->assertSame(ContentStatus::Corrigido, $content->status);
        $this->assertSame(2, $content->round);
        $this->assertTrue(Content::awaiting($this->carla)->whereKey($content->id)->exists());
        $this->assertFalse(Content::awaiting($this->bruna)->whereKey($content->id)->exists(), 'a Bruna já tinha aprovado');
    }

    public function test_asking_correction_on_a_scheduled_post_unschedules_it(): void
    {
        $content = $this->inPlanilha();
        $this->workflow->schedule($content, now()->addDay(), $this->ana);

        $this->workflow->review($content, $this->carla, ReviewDecision::Ajuste, 'Legenda longa');

        $this->assertSame(ContentStatus::Pendente, $content->status);
        $this->assertNull($content->scheduled_for);
    }

    public function test_change_request_needs_text_audio_or_image(): void
    {
        $content = $this->inPlanilha();

        $this->expectExceptionMessage('Explique o motivo');
        $this->workflow->review($content, $this->bruna, ReviewDecision::Ajuste);
    }

    public function test_audio_alone_is_enough_explanation(): void
    {
        $content = $this->inPlanilha();

        $review = $this->workflow->review($content, $this->bruna, ReviewDecision::Ajuste, audioPath: 'reviews/audio.webm');

        $this->assertSame('reviews/audio.webm', $review->audio_path);
    }

    public function test_only_approvers_review(): void
    {
        $content = $this->inPlanilha();

        $this->expectException(InvalidArgumentException::class);
        $this->workflow->review($content, $this->ana, ReviewDecision::Aprovado);
    }

    public function test_archive_restore_and_delete(): void
    {
        $content = $this->inPlanilha();
        $this->workflow->archive($content, $this->ana, 'repetido');
        $this->assertSame(ContentStatus::Arquivado, $content->status);

        $this->workflow->restore($content, $this->ana);
        $this->assertSame(ContentStatus::Novo, $content->status);

        $this->workflow->archive($content, $this->ana);
        $this->workflow->destroy($content);
        $this->assertModelMissing($content);
    }

    public function test_cannot_delete_without_archiving_first(): void
    {
        $content = $this->inPlanilha();

        $this->expectException(InvalidArgumentException::class);
        $this->workflow->destroy($content);
    }

    public function test_every_step_is_recorded_in_history(): void
    {
        $content = $this->inPlanilha();
        $this->workflow->review($content, $this->carla, ReviewDecision::Aprovado);
        $this->workflow->schedule($content, now()->addDay()->setTime(12, 0), $this->ana);
        $this->workflow->markPosted($content, $this->ana);

        $this->assertSame(
            ['enviado_para_planilha', 'enviado_para_aprovacao', 'aprovado', 'agendado', 'postado'],
            $content->events()->reorder('id')->pluck('type')->all(),
        );
    }
}
