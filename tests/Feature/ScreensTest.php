<?php

namespace Tests\Feature;

use App\Enums\Account;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\Cta;
use App\Enums\DriveFileState;
use App\Enums\ReviewDecision;
use App\Enums\Role;
use App\Models\Content;
use App\Models\DriveFile;
use App\Models\User;
use App\Services\ContentWorkflow;
use App\Support\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $bruna;

    private User $carla;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');

        $this->ana = User::create(['name' => 'Ana', 'role' => Role::Admin]);
        $this->bruna = User::create(['name' => 'Bruna', 'role' => Role::Aprovadora, 'review_order' => 1]);
        $this->carla = User::create(['name' => 'Carla', 'role' => Role::Aprovadora, 'review_order' => 2]);
    }

    private function content(array $overrides = []): Content
    {
        $content = Content::create($overrides + [
            'title' => 'Reels - Teste',
            'account' => Account::Principal,
            'type' => ContentType::Reel,
            'cta' => Cta::Organico,
            'caption' => 'Legenda',
            'drive_created_at' => now(),
        ]);
        DriveFile::create([
            'drive_file_id' => 'f'.$content->id,
            'content_id' => $content->id,
            'state' => DriveFileState::Imported,
            'name' => 'video.mp4',
            'mime_type' => 'video/mp4',
            'md5' => 'abc',
            'size' => 136135229,
            'width' => 1080,
            'height' => 1920,
            'duration_ms' => 71000,
        ]);

        return $content;
    }

    private function inPlanilha(array $overrides = []): Content
    {
        $content = $this->content($overrides);
        app(ContentWorkflow::class)->moveToPlanilha($content, $this->ana);
        app(ContentWorkflow::class)->requestApproval($content, $this->ana);

        return $content;
    }

    public function test_ana_sends_selected_items_from_the_planilha_to_approval(): void
    {
        $first = $this->content(['title' => 'Precisa aprovar']);
        $second = $this->content(['title' => 'Tráfego, não precisa']);
        app(ContentWorkflow::class)->moveToPlanilha($first, $this->ana);
        app(ContentWorkflow::class)->moveToPlanilha($second, $this->ana);

        $this->actingAs($this->ana)->get(route('contents.index', ['etapa' => 'planilha']))->assertSee('Não enviado')->assertSee('Vazio');

        $this->post(route('contents.approval-many'), ['ids' => [$first->id], 'acao' => 'enviar'])->assertSessionHas('status');

        $this->assertTrue($first->fresh()->needs_approval);
        $this->assertFalse($second->fresh()->needs_approval);
        $this->actingAs($this->bruna)->get(route('review.index'))->assertSee('Precisa aprovar')->assertDontSee('Tráfego, não precisa');
    }

    public function test_archiving_from_the_inbox_stays_in_the_inbox(): void
    {
        $older = $this->content(['title' => 'Mais antigo', 'cta' => null]);
        $newer = $this->content(['title' => 'Mais novo', 'cta' => null, 'drive_created_at' => now()->addMinute()]);

        $this->actingAs($this->ana)->post(route('contents.action', [$newer, 'arquivar']))
            ->assertRedirect(route('contents.show', $older));

        $this->post(route('contents.action', [$older, 'arquivar']))
            ->assertRedirect(route('contents.index', ['etapa' => 'entrada']));
    }

    public function test_reverting_a_rejection_needs_the_second_confirmation(): void
    {
        $content = $this->inPlanilha();
        app(ContentWorkflow::class)->review($content, $this->carla, ReviewDecision::Reprovado, 'Não combina');

        $this->actingAs($this->ana)->get(route('contents.show', $content))->assertSee('Reverter reprovação');

        $this->post(route('contents.action', [$content, 'reverter']))->assertSessionHasErrors();
        $this->assertTrue($content->fresh()->isRejected());

        $this->post(route('contents.action', [$content, 'reverter']), ['confirmo' => '1']);
        $this->assertFalse($content->fresh()->isRejected());
    }

    public function test_personal_link_logs_in_and_old_link_stops_working(): void
    {
        $old = $this->bruna->issueLoginToken();
        $new = $this->bruna->issueLoginToken();

        $this->get(route('login.link', $old))->assertSee('não funciona mais');
        $this->assertGuest();

        $this->get(route('login.link', $new))->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($this->bruna);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/conteudos')->assertRedirect(route('login'));
    }

    public function test_approvers_cannot_open_anas_area(): void
    {
        $this->actingAs($this->carla)->get('/conteudos')->assertRedirect(route('review.index'));
        $this->actingAs($this->carla)->get('/pessoas')->assertRedirect(route('review.index'));
    }

    public function test_every_tab_renders(): void
    {
        $this->content(['title' => 'Na entrada', 'cta' => null]);
        $this->inPlanilha(['title' => 'Na planilha']);

        foreach (array_keys(Pipeline::STAGES) as $stage) {
            $this->actingAs($this->ana)->get(route('contents.index', ['etapa' => $stage]))->assertOk();
        }
        $this->get(route('people.index'))->assertOk();
        $this->get(route('folders.index'))->assertOk();
        $this->get(route('contents.notify'))->assertRedirect(route('people.index'));
    }

    public function test_inbox_shows_what_is_missing(): void
    {
        $this->content(['title' => 'Sem CTA', 'cta' => null]);

        $this->actingAs($this->ana)->get('/conteudos')
            ->assertSee('Sem CTA')
            ->assertSee('Falta: CTA');
    }

    public function test_saving_area_type_and_cta_moves_to_planilha_and_opens_next(): void
    {
        $first = $this->content(['title' => 'Primeiro', 'cta' => null]);
        $second = $this->content(['title' => 'Segundo', 'cta' => null, 'drive_created_at' => now()->addMinute()]);

        // Caixa de entrada mostra os mais novos primeiro: Segundo, depois Primeiro.
        $this->actingAs($this->ana)->put(route('contents.update', $second), [
            'title' => 'Segundo', 'account' => 'podcast', 'type' => 'reel', 'cta' => 'trafego',
        ])->assertRedirect(route('contents.show', $first));

        $this->assertSame(ContentStatus::Pendente, $second->fresh()->status);
        $this->assertSame(Account::Podcast, $second->fresh()->account);
    }

    public function test_saving_without_cta_stays_in_inbox(): void
    {
        $content = $this->content(['cta' => null]);

        $this->actingAs($this->ana)->from(route('contents.show', $content))->put(route('contents.update', $content), [
            'title' => 'Ainda sem CTA', 'account' => 'principal', 'type' => 'reel',
        ])->assertRedirect(route('contents.show', $content));

        $this->assertSame(ContentStatus::Novo, $content->fresh()->status);
    }

    public function test_planilha_lists_columns_and_decisions(): void
    {
        $content = $this->inPlanilha(['title' => 'Reels da planilha', 'caption' => null]);
        app(ContentWorkflow::class)->review($content, $this->carla, ReviewDecision::Ajuste, 'Trocar a música');

        $this->actingAs($this->ana)->get(route('contents.index', ['etapa' => 'planilha']))
            ->assertOk()
            ->assertSee('Reels da planilha')
            ->assertSee('Sem legenda')
            ->assertSee('Correção');
    }

    public function test_content_page_shows_file_specs(): void
    {
        $content = $this->content();

        $this->actingAs($this->ana)->get(route('contents.show', $content))
            ->assertSee('1080 × 1920')
            ->assertSee('1:11')
            ->assertSee('129,8 MB');
    }

    public function test_whatsapp_notices_carry_each_personal_login_link(): void
    {
        $this->inPlanilha();
        $brunaLink = route('login.link', $this->bruna->issueLoginToken());
        $carlaLink = route('login.link', $this->carla->issueLoginToken());

        $page = $this->actingAs($this->ana)->get(route('people.index'))->assertOk();

        // Cada mensagem (individual e a do grupo) leva o link de quem recebe.
        $page->assertSee(rawurlencode($brunaLink), false)->assertSee(rawurlencode($carlaLink), false);

        // E o link do aviso funciona em outro aparelho: entra e cai na lista de aprovação.
        auth()->logout();
        $this->get($brunaLink)->assertRedirect(route('home'));
        $this->get(route('home'))->assertRedirect(route('review.index'));
    }

    public function test_old_links_without_a_stored_copy_ask_for_a_new_one(): void
    {
        $this->inPlanilha();
        $this->carla->forceFill(['login_token_hash' => hash('sha256', 'antigo'), 'login_token_encrypted' => null])->save();

        $this->actingAs($this->ana)->get(route('people.index'))
            ->assertSee('A mensagem de Bruna e Carla ainda vai sem o link pessoal');
    }

    public function test_sending_to_approval_shows_the_whatsapp_buttons_right_away(): void
    {
        $content = $this->content();
        app(ContentWorkflow::class)->moveToPlanilha($content, $this->ana);
        $this->bruna->issueLoginToken();

        $this->actingAs($this->ana)
            ->post(route('contents.action', [$content, 'pedir-aprovacao']))
            ->assertSessionHas('notify', true);

        $this->get(route('contents.show', $content))
            ->assertSee('Enviado! Agora avise no WhatsApp')
            ->assertSee('https://wa.me/?text=', false);
    }

    public function test_change_request_with_only_audio_or_only_screenshot(): void
    {
        $content = $this->inPlanilha();

        // Só áudio, pelo painel (fetch, resposta em JSON).
        $this->actingAs($this->bruna)->postJson(route('review.store', $content), [
            'decision' => 'ajuste',
            'audio' => UploadedFile::fake()->create('audio.webm', 50, 'audio/webm'),
        ])->assertOk()->assertJsonStructure(['redirect']);

        // Só print.
        $this->actingAs($this->carla)->postJson(route('review.store', $content), [
            'decision' => 'ajuste',
            'images' => [UploadedFile::fake()->image('print.png')],
        ])->assertOk();

        [$carlaReview, $brunaReview] = $content->reviews()->get()->all();
        Storage::disk('local')->assertExists($brunaReview->audio_path);
        $this->assertCount(1, $carlaReview->attachments);

        $this->actingAs($this->ana)->get(route('contents.show', $content))
            ->assertSee(route('media.audio', $brunaReview))
            ->assertSee(route('media.attachment', $carlaReview->attachments->first()));
        $this->get(route('media.audio', $brunaReview))->assertOk();
    }

    public function test_change_request_with_nothing_is_refused_as_json(): void
    {
        $content = $this->inPlanilha();

        $this->actingAs($this->bruna)->postJson(route('review.store', $content), ['decision' => 'ajuste'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Explique o motivo: texto, áudio ou imagem.');
    }

    public function test_each_approver_has_her_own_queue(): void
    {
        $first = $this->inPlanilha(['title' => 'Primeiro']);
        $second = $this->inPlanilha(['title' => 'Segundo']);

        // A Carla pode começar sem esperar a Bruna.
        $this->actingAs($this->carla)
            ->post(route('review.store', $first), ['decision' => 'aprovado'])
            ->assertRedirect(route('review.show', $second));

        $this->actingAs($this->bruna)->get(route('review.index'))->assertSee('Primeiro')->assertSee('Segundo');
    }

    public function test_archived_can_be_deleted_only_with_confirmation(): void
    {
        $content = $this->content();
        $this->actingAs($this->ana)->post(route('contents.action', [$content, 'arquivar']), ['motivo' => 'duplicado']);
        $this->assertSame(ContentStatus::Arquivado, $content->fresh()->status);

        $this->post(route('contents.action', [$content, 'excluir']))->assertSessionHasErrors();
        $this->assertModelExists($content);

        $this->post(route('contents.action', [$content, 'excluir']), ['confirmacao' => 'EXCLUIR'])
            ->assertRedirect(route('contents.index', ['etapa' => 'arquivados']));
        $this->assertModelMissing($content);
        $this->assertSame(DriveFileState::Ignored, DriveFile::firstWhere('drive_file_id', 'f'.$content->id)->state);
    }

    public function test_media_is_served_with_range_support_once_cached(): void
    {
        $content = $this->content();
        $file = $content->files->first();

        $this->actingAs($this->bruna)->get(route('media.file', $file))->assertNotFound();

        Storage::disk('local')->put('media/test.mp4', str_repeat('x', 1000));
        $file->update(['cache_path' => 'media/test.mp4', 'cached_md5' => 'abc']);

        $this->get(route('media.file', $file), ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '100');
    }

    public function test_people_page_generates_a_link_once(): void
    {
        $this->actingAs($this->ana)->post(route('people.link', $this->carla))->assertSessionHas('link');

        $this->assertNotNull($this->carla->fresh()->login_token_hash);
    }
}
