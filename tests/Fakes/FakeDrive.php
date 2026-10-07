<?php

namespace Tests\Fakes;

use App\Services\Drive\DriveApi;

/**
 * Drive em memória: os testes montam pastas/arquivos e geram "changes"
 * do mesmo jeito que a API real devolveria.
 */
class FakeDrive implements DriveApi
{
    /** @var array<string, array> */
    public array $items = [];

    private array $changes = [];

    private int $nextId = 1;

    public array $downloads = [];

    /** ID que faz getFile() falhar, para simular erro da API. */
    public ?string $failOn = null;

    public function folder(string $name, ?string $parent = null, ?string $id = null): string
    {
        $id ??= 'folder'.$this->nextId++;
        $this->items[$id] = ['id' => $id, 'name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => $parent ? [$parent] : []];
        $this->changes[] = ['fileId' => $id, 'removed' => false];

        return $id;
    }

    public function upload(string $name, string $parent, string $mime = 'video/mp4', array $extra = []): string
    {
        $id = 'file'.$this->nextId++;
        $this->items[$id] = $extra + [
            'id' => $id,
            'name' => $name,
            'mimeType' => $mime,
            'parents' => [$parent],
            'createdTime' => '2026-10-05T12:00:00Z',
            'modifiedTime' => '2026-10-05T12:00:00Z',
            'size' => '1000',
            'md5Checksum' => md5($id.'v1'),
            'webViewLink' => "https://drive.google.com/file/d/{$id}/view",
            'owners' => [['displayName' => 'Ana Carvo', 'emailAddress' => 'ana@example.com']],
        ];
        $this->changes[] = ['fileId' => $id, 'removed' => false];

        return $id;
    }

    /** Nova versão do mesmo arquivo (mesmo ID, conteúdo diferente). */
    public function replace(string $id): void
    {
        $this->items[$id]['md5Checksum'] = md5($id.uniqid());
        $this->items[$id]['modifiedTime'] = '2026-10-06T12:00:00Z';
        $this->changes[] = ['fileId' => $id, 'removed' => false];
    }

    public function move(string $id, string $newParent): void
    {
        $this->items[$id]['parents'] = [$newParent];
        $this->changes[] = ['fileId' => $id, 'removed' => false];
    }

    public function rename(string $id, string $name): void
    {
        $this->items[$id]['name'] = $name;
        $this->changes[] = ['fileId' => $id, 'removed' => false];
    }

    public function trash(string $id): void
    {
        $this->items[$id]['trashed'] = true;
        $this->changes[] = ['fileId' => $id, 'removed' => false];
    }

    public function getFile(string $id): ?array
    {
        if ($id === $this->failOn) {
            throw new \RuntimeException('403 Forbidden');
        }

        return $this->items[$id] ?? null;
    }

    public function listChildren(string $folderId): iterable
    {
        foreach ($this->items as $item) {
            if (($item['parents'][0] ?? null) === $folderId && ! ($item['trashed'] ?? false)) {
                yield $item;
            }
        }
    }

    public function getStartPageToken(): string
    {
        return (string) count($this->changes);
    }

    public function listChanges(string $pageToken): array
    {
        $changes = array_map(
            fn ($change) => $change + ['file' => $this->items[$change['fileId']] ?? null],
            array_slice($this->changes, (int) $pageToken),
        );

        return ['changes' => $changes, 'nextPageToken' => null, 'newStartPageToken' => (string) count($this->changes)];
    }

    public function download(string $id, string $destination): void
    {
        $this->downloads[] = $id;
        file_put_contents($destination, 'conteudo de '.$id);
    }

    public function downloadThumbnail(string $id, string $destination): bool
    {
        file_put_contents($destination, 'miniatura de '.$id);

        return true;
    }
}
