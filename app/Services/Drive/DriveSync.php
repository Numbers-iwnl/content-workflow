<?php

namespace App\Services\Drive;

use App\Enums\Account;
use App\Enums\ContentStatus;
use App\Enums\DriveFileState;
use App\Jobs\CacheDriveFile;
use App\Models\Content;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Setting;
use App\Models\WatchedFolder;
use App\Services\ContentWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Mantém o catálogo em dia com o Drive.
 *
 *  - baseline(): quando uma pasta passa a ser monitorada, tudo o que já
 *    existe nela é registrado como "baseline" e nunca vira conteúdo.
 *  - syncChanges(): a cada poucos minutos, pergunta ao Drive o que mudou
 *    desde a última vez (changes.list) e processa só isso.
 *  - reconcile(): varredura completa das pastas, como rede de segurança
 *    para qualquer mudança que o feed não tenha trazido.
 */
class DriveSync
{
    private const TOKEN_KEY = 'drive.changes_token';

    private const MAX_DEPTH = 30;

    /** @var Collection<string, WatchedFolder>|null */
    private ?Collection $watched = null;

    private array $stats = [];

    public function __construct(
        private readonly DriveApi $drive,
        private readonly ContentRules $rules,
        private readonly ContentWorkflow $workflow,
    ) {}

    /**
     * Cadastra uma pasta (link ou ID) para ser monitorada. A varredura
     * inicial (baseline) é feita à parte, porque pode levar minutos.
     */
    public function register(string $linkOrId, ?Account $account): WatchedFolder
    {
        $id = preg_match('#folders/([\w-]+)#', $linkOrId, $m) ? $m[1] : trim($linkOrId);
        $folder = $this->drive->getFile($id);

        if (! $folder || $folder['mimeType'] !== DriveApi::FOLDER_MIME) {
            throw new InvalidArgumentException('Pasta não encontrada. Ela foi compartilhada com '.config('services.google.client_email', 'a conta de serviço').'?');
        }

        $this->watched = null;

        return WatchedFolder::updateOrCreate(
            ['drive_folder_id' => $id],
            ['name' => trim($folder['name']), 'default_account' => $account, 'active' => true],
        );
    }

    public function baseline(WatchedFolder $folder): array
    {
        $this->stats = [];
        $this->ensureChangesToken();

        $this->crawl($folder->drive_folder_id, [$folder->name], function (array $file, array $path) use ($folder) {
            DriveFile::firstOrCreate(
                ['drive_file_id' => $file['id']],
                $this->fileAttributes($file, $path) + [
                    'watched_folder_id' => $folder->id,
                    'state' => DriveFileState::Baseline,
                ],
            );
            $this->count('baseline');
        });

        $folder->forceFill(['baseline_completed_at' => now(), 'last_reconciled_at' => now()])->save();
        $this->watched = null;

        return $this->stats;
    }

    /**
     * Traz para a caixa de entrada o que já estava nas pastas quando elas
     * passaram a ser monitoradas (baseline), a partir de uma data de criação.
     * Passa pelas mesmas regras de um arquivo novo.
     */
    public function backfill(Carbon $since): array
    {
        $this->stats = [];

        $files = DriveFile::where('state', DriveFileState::Baseline)
            ->where('drive_created_at', '>=', $since)
            ->whereHas('watchedFolder', fn ($q) => $q->where('active', true))
            ->orderBy('drive_created_at')
            ->get();

        foreach ($files as $baseline) {
            try {
                $file = $this->drive->getFile($baseline->drive_file_id);

                if (! $file || ($file['trashed'] ?? false)) {
                    $baseline->update(['state' => DriveFileState::Removed]);
                    $this->count('removed');

                    continue;
                }

                // Sai do "já existia" e entra como se tivesse acabado de chegar.
                $baseline->delete();
                $this->handleFile($file);
            } catch (Throwable $e) {
                report($e);
                $this->count('errors');
            }
        }

        return $this->stats;
    }

    public function reconcile(WatchedFolder $folder): array
    {
        $this->stats = [];

        $this->crawl($folder->drive_folder_id, [$folder->name], fn (array $file) => $this->handleFile($file));

        $folder->forceFill(['last_reconciled_at' => now()])->save();

        return $this->stats;
    }

    public function syncChanges(): array
    {
        $this->stats = [];
        $token = Setting::get(self::TOKEN_KEY);

        if (! $token) {
            $this->ensureChangesToken();

            return ['initialized' => 1];
        }

        $errors = [];

        try {
            while (true) {
                $page = $this->drive->listChanges($token);

                foreach ($page['changes'] as $change) {
                    // Um arquivo problemático não pode travar a fila inteira:
                    // registra o erro e segue para o próximo.
                    try {
                        $file = $change['file'] ?? null;

                        if (($change['removed'] ?? false) || ! $file || ($file['trashed'] ?? false)) {
                            $this->handleRemoval($change['fileId'] ?? $file['id'] ?? null);
                        } else {
                            $this->handleFile($file);
                        }
                    } catch (Throwable $e) {
                        report($e);
                        $errors[] = ($change['file']['name'] ?? $change['fileId'] ?? '?').': '.$e->getMessage();
                        $this->count('errors');
                    }
                }

                $token = $page['nextPageToken'] ?? $page['newStartPageToken'];
                Setting::put(self::TOKEN_KEY, $token);

                if (! $page['nextPageToken']) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Falha geral (rede, credencial, API): fica visível na tela de Pastas.
            Setting::put('drive.last_error', json_encode(['at' => now()->toIso8601String(), 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE));

            throw $e;
        }

        Setting::put('drive.last_sync_at', now()->toIso8601String());
        Setting::put('drive.last_error', $errors
            ? json_encode(['at' => now()->toIso8601String(), 'message' => count($errors).' arquivo(s) com problema: '.implode(' | ', array_slice($errors, 0, 3))], JSON_UNESCAPED_UNICODE)
            : null);

        return $this->stats;
    }

    /** Processa um arquivo ou pasta que apareceu ou mudou no Drive. */
    public function handleFile(array $file): void
    {
        if ($file['mimeType'] === DriveApi::FOLDER_MIME) {
            $this->rememberFolder($file);

            return;
        }

        $existing = DriveFile::firstWhere('drive_file_id', $file['id']);
        $location = $this->locate($file['parents'][0] ?? null);

        if (! $location) {
            // Fora das pastas monitoradas (ou foi movido para fora).
            if ($existing && $existing->state === DriveFileState::Imported) {
                $this->handleRemoval($file['id']);
            }
            $this->count('outside');

            return;
        }

        [$watched, $path] = $location;

        if ($existing?->state === DriveFileState::Ignored && ! $this->rules->ignoreReason($file, $path)) {
            // Foi renomeado ou movido para um lugar que as regras aceitam.
            $existing->delete();
            $existing = null;
        }

        if ($existing) {
            $this->updateExisting($existing, $file, $path);

            return;
        }

        if (! $watched->baseline_completed_at) {
            return; // a varredura inicial ainda vai registrar este arquivo
        }

        $this->importNew($file, $path, $watched);
    }

    private function updateExisting(DriveFile $existing, array $file, array $path): void
    {
        $previousVersion = $existing->versionKey();
        $existing->fill($this->fileAttributes($file, $path));

        if ($existing->state === DriveFileState::Removed && $existing->content_id) {
            $existing->state = DriveFileState::Imported;
        }

        $existing->save();

        if ($existing->state !== DriveFileState::Imported || ! $existing->content) {
            return;
        }

        if ($existing->versionKey() !== $previousVersion) {
            $this->workflow->fileChanged($existing->content, ['arquivo' => $existing->name]);
            CacheDriveFile::dispatch($existing->id);
            $this->count('updated');
        }
    }

    private function importNew(array $file, array $path, WatchedFolder $watched): void
    {
        $attributes = $this->fileAttributes($file, $path) + ['watched_folder_id' => $watched->id];

        if ($reason = $this->rules->ignoreReason($file, $path)) {
            DriveFile::create($attributes + ['state' => DriveFileState::Ignored, 'ignore_reason' => $reason]);
            $this->count('ignored');

            return;
        }

        $carousel = $this->rules->isCarouselImage($file, $path);
        $content = $carousel ? $this->openCarousel($attributes['parent_id']) : null;

        if ($content) {
            // Imagem nova num carrossel que já está na planilha: se havia
            // correção pedida, é a correção chegando.
            if ($content->status !== ContentStatus::Novo) {
                $this->workflow->fileChanged($content, ['arquivo_adicionado' => $file['name']]);
            }
        } else {
            $content = $this->createContent($file, $path, $watched, $carousel, $attributes['parent_id']);
        }

        $driveFile = DriveFile::create($attributes + [
            'content_id' => $content->id,
            'state' => DriveFileState::Imported,
            'position' => self::positionFromName($file['name']),
        ]);

        CacheDriveFile::dispatch($driveFile->id);
        $this->count('imported');
    }

    private function createContent(array $file, array $path, WatchedFolder $watched, bool $carousel, ?string $parentId): Content
    {
        $content = Content::create($this->rules->suggest($file, $path, $watched->default_account, $carousel) + [
            'status' => ContentStatus::Novo,
            'watched_folder_id' => $watched->id,
            'group_folder_id' => $carousel ? $parentId : null,
            'drive_path' => implode(' / ', $path),
            'drive_created_at' => self::driveTime($file['createdTime'] ?? null) ?? now(),
            'suggested_correction_of_id' => $carousel ? null : $this->findCorrectionTarget($file, $parentId)?->id,
        ]);

        $this->workflow->log($content, 'catalogado', null, to: ContentStatus::Novo, data: ['caminho' => $content->drive_path]);

        return $content;
    }

    /** Carrossel ainda "aberto" naquela pasta (imagens chegam uma a uma). */
    private function openCarousel(?string $folderId): ?Content
    {
        if (! $folderId) {
            return null;
        }

        return Content::where('group_folder_id', $folderId)
            ->whereNotIn('status', [ContentStatus::Postado, ContentStatus::Arquivado])
            ->latest('id')
            ->first();
    }

    /**
     * Arquivo novo com nome parecido com um conteúdo aguardando correção na
     * mesma pasta: provavelmente é a correção que subiram como arquivo novo.
     */
    private function findCorrectionTarget(array $file, ?string $parentId): ?Content
    {
        if (! $parentId) {
            return null;
        }

        $name = self::normalizeName($file['name']);
        $threshold = config('conteudo.correction_name_similarity');

        return DriveFile::query()
            ->where('parent_id', $parentId)
            ->where('state', DriveFileState::Imported)
            ->whereHas('content', fn ($q) => $q->whereIn('status', ContentStatus::planilha()))
            ->with('content.reviews')
            ->get()
            ->filter(fn (DriveFile $candidate) => $candidate->content->hasPendingCorrection())
            ->map(function (DriveFile $candidate) use ($name) {
                similar_text($name, self::normalizeName($candidate->name), $percent);

                return [$candidate->content, $percent];
            })
            ->filter(fn ($pair) => $pair[1] >= $threshold)
            ->sortByDesc(fn ($pair) => $pair[1])
            ->first()[0] ?? null;
    }

    private function handleRemoval(?string $driveFileId): void
    {
        $existing = $driveFileId ? DriveFile::firstWhere('drive_file_id', $driveFileId) : null;

        if (! $existing || $existing->state === DriveFileState::Removed) {
            return;
        }

        $wasImported = $existing->state === DriveFileState::Imported;
        $existing->update(['state' => DriveFileState::Removed]);
        $this->count('removed');

        $content = $existing->content;
        if (! $wasImported || ! $content) {
            return;
        }

        $remaining = $content->files()->where('state', DriveFileState::Imported)->exists();

        if (! $remaining && $content->status === ContentStatus::Novo) {
            $this->workflow->archive($content, null, 'arquivo apagado ou movido no Drive');
        } else {
            $this->workflow->log($content, 'arquivo_removido', null, data: ['arquivo' => $existing->name]);
        }
    }

    /**
     * Sobe pela árvore de pastas até achar uma pasta monitorada.
     *
     * @return array{0: WatchedFolder, 1: array<string>}|null
     */
    private function locate(?string $folderId): ?array
    {
        $names = [];

        for ($depth = 0; $folderId && $depth < self::MAX_DEPTH; $depth++) {
            $folder = DriveFolder::find($folderId) ?? $this->fetchFolder($folderId);
            if (! $folder) {
                return null;
            }

            $names[] = $folder->name;

            if ($watched = $this->watchedFolders()->get($folderId)) {
                return [$watched, array_reverse($names)];
            }

            $folderId = $folder->parent_id;
        }

        return null;
    }

    private function fetchFolder(string $id): ?DriveFolder
    {
        $folder = $this->drive->getFile($id);

        return $folder ? $this->rememberFolder($folder) : null;
    }

    private function rememberFolder(array $folder): DriveFolder
    {
        return DriveFolder::updateOrCreate(['id' => $folder['id']], [
            'name' => trim($folder['name']),
            'parent_id' => $folder['parents'][0] ?? null,
        ]);
    }

    /** Percorre a árvore toda, chamando $onFile para cada arquivo (não pasta). */
    private function crawl(string $folderId, array $path, callable $onFile): void
    {
        foreach ($this->drive->listChildren($folderId) as $item) {
            if ($item['mimeType'] === DriveApi::FOLDER_MIME) {
                $this->rememberFolder($item);
                $this->crawl($item['id'], [...$path, $item['name']], $onFile);
            } else {
                $onFile($item, $path);
            }
        }
    }

    private function ensureChangesToken(): void
    {
        if (! Setting::get(self::TOKEN_KEY)) {
            Setting::put(self::TOKEN_KEY, $this->drive->getStartPageToken());
        }
    }

    private function watchedFolders(): Collection
    {
        return $this->watched ??= WatchedFolder::where('active', true)->get()->keyBy('drive_folder_id');
    }

    private function fileAttributes(array $file, array $path): array
    {
        $owner = $file['owners'][0] ?? [];

        return [
            'drive_file_id' => $file['id'],
            'parent_id' => $file['parents'][0] ?? null,
            'name' => $file['name'],
            'path' => implode(' / ', $path),
            'mime_type' => $file['mimeType'],
            'size' => $file['size'] ?? null,
            'md5' => $file['md5Checksum'] ?? null,
            'head_revision_id' => $file['headRevisionId'] ?? null,
            'web_view_link' => $file['webViewLink'] ?? null,
            'owner_name' => $owner['displayName'] ?? null,
            'owner_email' => $owner['emailAddress'] ?? null,
            'drive_created_at' => self::driveTime($file['createdTime'] ?? null),
            'drive_modified_at' => self::driveTime($file['modifiedTime'] ?? null),
            'width' => $file['imageMediaMetadata']['width'] ?? $file['videoMediaMetadata']['width'] ?? null,
            'height' => $file['imageMediaMetadata']['height'] ?? $file['videoMediaMetadata']['height'] ?? null,
            'duration_ms' => $file['videoMediaMetadata']['durationMillis'] ?? null,
        ];
    }

    private function count(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
    }

    /** O Drive manda horários em UTC; o banco guarda no fuso do app (São Paulo). */
    private static function driveTime(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    /** "MB2026_CapaRevista-Joana-03.png" → 3 (ordem do card no carrossel). */
    private static function positionFromName(string $name): int
    {
        return preg_match('/(\d+)\D*$/', ContentRules::stripExtension($name), $m) ? min((int) $m[1], 65535) : 0;
    }

    private static function normalizeName(string $name): string
    {
        $name = mb_strtolower(ContentRules::stripExtension($name));

        return trim(preg_replace('/[\s_\-()]*(v\d+|vers[ãa]o\s*\d+|final|corrigid[oa]|ajustad[oa])[\s_\-()]*/u', ' ', $name));
    }
}
