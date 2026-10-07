<?php

namespace Tests\Feature;

use App\Enums\Account;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\Cta;
use App\Enums\DriveFileState;
use App\Enums\ReviewDecision;
use App\Enums\Role;
use App\Jobs\CacheDriveFile;
use App\Models\Content;
use App\Models\DriveFile;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\ContentWorkflow;
use App\Services\Drive\DriveApi;
use App\Services\Drive\DriveSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeDrive;
use Tests\TestCase;

class DriveSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeDrive $drive;

    private string $videos;

    private string $renato;

    private string $estaticos;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->drive = new FakeDrive;
        $this->app->instance(DriveApi::class, $this->drive);

        // Mesma estrutura do Drive real.
        $root = $this->drive->folder('CONTEÚDO INSTAGRAM');
        $this->videos = $this->drive->folder('VÍDEOS PARA CONFERÊNCIA', $root);
        $year = $this->drive->folder('2026', $this->videos);
        $this->renato = $this->drive->folder('2026 - VIDEOMAKER 01 - RENATO', $year);
        $this->estaticos = $this->drive->folder('ESTÁTICOS PARA CONFERÊNCIA', $root);

        // Um arquivo antigo, que já existia antes do sistema.
        $this->drive->upload('Reels antigo.mp4', $this->renato);

        $this->watch($this->videos, Account::Principal);
        $this->watch($this->estaticos, Account::Principal);
    }

    private function watch(string $folderId, ?Account $account): WatchedFolder
    {
        $watched = WatchedFolder::create([
            'drive_folder_id' => $folderId,
            'name' => $this->drive->items[$folderId]['name'],
            'default_account' => $account,
        ]);
        $this->sync()->baseline($watched);

        return $watched;
    }

    private function sync(): DriveSync
    {
        return app(DriveSync::class);
    }

    public function test_existing_files_are_baseline_and_never_become_content(): void
    {
        $this->sync()->syncChanges();

        $this->assertSame(0, Content::count());
        $this->assertSame(DriveFileState::Baseline, DriveFile::first()->state);
    }

    public function test_new_video_becomes_content_with_suggested_fields(): void
    {
        $this->drive->upload('Vídeo para tráfego - Carla 01.mp4', $this->renato);

        $stats = $this->sync()->syncChanges();

        $this->assertSame(1, $stats['imported']);
        $content = Content::sole();
        $this->assertSame(ContentStatus::Novo, $content->status);
        $this->assertSame('Vídeo para tráfego - Carla 01', $content->title);
        $this->assertSame(ContentType::Reel, $content->type);
        $this->assertSame(Account::Principal, $content->account);
        $this->assertSame(Cta::Trafego, $content->cta);
        $this->assertSame('Renato', $content->produced_by);
        $this->assertSame('VÍDEOS PARA CONFERÊNCIA / 2026 / 2026 - VIDEOMAKER 01 - RENATO', $content->drive_path);
        Queue::assertPushed(CacheDriveFile::class);
    }

    public function test_noise_is_ignored_by_the_rules(): void
    {
        $fotos = $this->drive->folder('Fotos Advisory', $this->renato);
        $this->drive->upload('DSC01795.ARW', $fotos, 'image/x-sony-arw');
        $this->drive->upload('DSC01795.JPG', $fotos, 'image/jpeg');
        $antiga = $this->drive->folder('VERSÃO ANTIGA', $this->renato);
        $this->drive->upload('01 - Video Times Square.mp4', $antiga);
        $this->drive->upload('Video.prproj', $this->renato, 'application/octet-stream');
        $this->drive->upload('Legenda', $this->renato, 'application/vnd.google-apps.document');

        $stats = $this->sync()->syncChanges();

        $this->assertSame(5, $stats['ignored']);
        $this->assertSame(0, Content::count());
    }

    public function test_quotes_com_fotos_is_not_mistaken_for_a_raw_photo_folder(): void
    {
        $quotes = $this->drive->folder('QUOTES COM FOTOS', $this->estaticos);
        $this->drive->upload('Quote 01.png', $quotes, 'image/png');

        $this->sync()->syncChanges();

        $this->assertSame(ContentType::Estatico, Content::sole()->type);
        $this->assertSame('QUOTES COM FOTOS', Content::sole()->project);
    }

    public function test_carousel_images_are_grouped_into_one_content(): void
    {
        $carrosseis = $this->drive->folder('CARROSSEIS', $this->estaticos);
        $post = $this->drive->folder('CARROSSEL 5 PONTOS CEGOS', $carrosseis);
        $this->drive->upload('MB2026_Carrossel-02.png', $post, 'image/png');
        $this->drive->upload('MB2026_Carrossel-01.png', $post, 'image/png');
        $this->sync()->syncChanges();

        // Mais uma imagem chega depois.
        $this->drive->upload('MB2026_Carrossel-03.png', $post, 'image/png');
        $this->sync()->syncChanges();

        $content = Content::sole();
        $this->assertSame(ContentType::Carrossel, $content->type);
        $this->assertSame('CARROSSEL 5 PONTOS CEGOS', $content->title);
        $this->assertSame(
            ['MB2026_Carrossel-01.png', 'MB2026_Carrossel-02.png', 'MB2026_Carrossel-03.png'],
            $content->files->pluck('name')->all(),
        );
    }

    public function test_magazine_covers_are_carousels_even_outside_carousel_folders(): void
    {
        $bd = $this->drive->folder('Marca B', $this->estaticos);
        $capas = $this->drive->folder('Capas de Revista', $bd);
        $joana = $this->drive->folder('Joana Lima', $capas);
        $this->drive->upload('MB2026_CapaRevista-JoanaLima-01.png', $joana, 'image/png');
        $this->drive->upload('MB2026_CapaRevista-JoanaLima-02.png', $joana, 'image/png');

        $this->sync()->syncChanges();

        $content = Content::sole();
        $this->assertSame(ContentType::Carrossel, $content->type);
        $this->assertSame('Joana Lima', $content->title);
        $this->assertSame('Capas de Revista', $content->project);
        $this->assertSame(Account::MarcaB, $content->account);
        $this->assertSame('Ana', $content->produced_by);
    }

    public function test_marca_b_statics_go_to_the_marca_b_account(): void
    {
        $bd = $this->drive->folder('Marca B', $this->estaticos);
        $this->drive->upload('Card prova social.png', $bd, 'image/png');

        $this->sync()->syncChanges();

        $this->assertSame(Account::MarcaB, Content::sole()->account);
    }

    public function test_replacing_the_file_during_correction_marks_it_corrected(): void
    {
        [$bruna] = $this->approvers();
        $fileId = $this->drive->upload('Reels - Brilho nos olhos.mp4', $this->renato);
        $this->sync()->syncChanges();
        $content = $this->readyForReview(Content::sole());
        app(ContentWorkflow::class)->review($content, $bruna, ReviewDecision::Ajuste, 'Trocar a trilha');

        $this->drive->replace($fileId);
        $this->sync()->syncChanges();

        $this->assertSame(ContentStatus::Corrigido, $content->fresh()->status);
        $this->assertSame(1, Content::count());
    }

    public function test_correction_uploaded_as_new_file_is_suggested_as_link(): void
    {
        [$bruna] = $this->approvers();
        $this->drive->upload('Reels - Novo nível.mp4', $this->renato);
        $this->sync()->syncChanges();
        $original = $this->readyForReview(Content::sole());
        app(ContentWorkflow::class)->review($original, $bruna, ReviewDecision::Ajuste, 'Cortar o começo');

        $this->drive->upload('Reels - Novo nível (VERSÃO 2).mp4', $this->renato);
        $this->sync()->syncChanges();

        $new = Content::latest('id')->first();
        $this->assertSame($original->id, $new->suggested_correction_of_id);

        app(ContentWorkflow::class)->linkAsCorrection($new, $original->fresh(), User::where('role', Role::Admin)->first());

        $this->assertSame(ContentStatus::Corrigido, $original->fresh()->status);
        $this->assertSame(['Reels - Novo nível (VERSÃO 2).mp4'], $original->fresh()->files->pluck('name')->all());
        $this->assertSame(1, Content::count());
    }

    public function test_deleting_a_new_file_removes_it_from_the_inbox(): void
    {
        $fileId = $this->drive->upload('Engano.mp4', $this->renato);
        $this->sync()->syncChanges();

        $this->drive->trash($fileId);
        $this->sync()->syncChanges();

        $this->assertSame(ContentStatus::Arquivado, Content::sole()->status);
    }

    public function test_ignored_file_renamed_to_valid_name_is_imported(): void
    {
        $fileId = $this->drive->upload('DSC0001.mp4', $this->renato);
        $this->sync()->syncChanges();
        $this->assertSame(0, Content::count());

        $this->drive->rename($fileId, 'Reels - Depoimento.mp4');
        $this->sync()->syncChanges();

        $this->assertSame('Reels - Depoimento', Content::sole()->title);
    }

    public function test_files_outside_watched_folders_are_skipped(): void
    {
        $other = $this->drive->folder('LEGENDAS');
        $this->drive->upload('Algo.mp4', $other);

        $this->sync()->syncChanges();

        $this->assertSame(0, Content::count());
        $this->assertSame(0, DriveFile::where('name', 'Algo.mp4')->count());
    }

    public function test_backfill_brings_existing_files_since_a_date_into_the_inbox(): void
    {
        // "Reels antigo.mp4" já existia antes do sistema (baseline), criado em 05/10/2026.
        $recent = DriveFile::firstWhere('name', 'Reels antigo.mp4');
        $this->assertSame(DriveFileState::Baseline, $recent->state);

        // Um arquivo bem mais velho, que não deve vir.
        $oldId = $this->drive->upload('Reels de agosto.mp4', $this->renato, extra: ['createdTime' => '2026-08-01T12:00:00Z']);
        DriveFile::create([
            'drive_file_id' => $oldId, 'watched_folder_id' => $recent->watched_folder_id, 'parent_id' => $this->renato,
            'state' => DriveFileState::Baseline, 'name' => 'Reels de agosto.mp4', 'mime_type' => 'video/mp4',
            'drive_created_at' => '2026-08-01 09:00:00',
        ]);

        $stats = $this->sync()->backfill(\Illuminate\Support\Carbon::parse('2026-09-27'));

        $this->assertSame(1, $stats['imported']);
        $content = Content::sole();
        $this->assertSame('Reels antigo', $content->title);
        $this->assertSame(ContentStatus::Novo, $content->status);
        $this->assertSame('Renato', $content->produced_by);
        $this->assertSame(DriveFileState::Baseline, DriveFile::firstWhere('drive_file_id', $oldId)->state);

        // Rodar de novo não duplica nada.
        $this->assertSame([], $this->sync()->backfill(\Illuminate\Support\Carbon::parse('2026-09-27')));
    }

    public function test_one_broken_file_does_not_block_the_rest(): void
    {
        // Pasta que a API se recusa a mostrar (ex.: permissão estranha no Drive).
        $broken = 'broken-folder';
        $this->drive->failOn = $broken;
        $this->drive->upload('Arquivo problemático.mp4', $broken);
        $this->drive->upload('Reels bom.mp4', $this->renato);

        $stats = $this->sync()->syncChanges();

        $this->assertSame(1, $stats['imported']);
        $this->assertSame('Reels bom', Content::sole()->title);
        $this->assertNotNull(\App\Models\Setting::get('drive.last_sync_at'));
        $this->assertStringContainsString('Arquivo problemático', \App\Models\Setting::get('drive.last_error'));
    }

    public function test_reconcile_catches_files_the_change_feed_missed(): void
    {
        $this->sync()->syncChanges();
        // Arquivo que o feed não trouxe: só aparece na varredura.
        $this->drive->items['ghost'] =['id' => 'ghost', 'name' => 'Perdido.mp4', 'mimeType' => 'video/mp4', 'parents' => [$this->renato]];

        $this->sync()->reconcile(WatchedFolder::first());

        $this->assertSame('Perdido', Content::sole()->title);
    }

    private function approvers(): array
    {
        User::create(['name' => 'Ana', 'role' => Role::Admin]);

        return [
            User::create(['name' => 'Bruna', 'role' => Role::Aprovadora, 'review_order' => 1]),
            User::create(['name' => 'Carla', 'role' => Role::Aprovadora, 'review_order' => 2]),
        ];
    }

    private function readyForReview(Content $content): Content
    {
        $content->update(['caption' => 'Legenda', 'cta' => Cta::Organico]);
        app(ContentWorkflow::class)->moveToPlanilha($content, User::where('role', Role::Admin)->first());
        app(ContentWorkflow::class)->requestApproval($content, User::where('role', Role::Admin)->first());

        return $content->fresh();
    }
}
