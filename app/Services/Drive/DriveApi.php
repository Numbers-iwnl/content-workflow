<?php

namespace App\Services\Drive;

/**
 * O pouco da API do Google Drive que o sistema usa. Arquivos chegam como
 * arrays no formato da API v3 (id, name, mimeType, parents, md5Checksum...).
 */
interface DriveApi
{
    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public const FILE_FIELDS = 'id,name,mimeType,parents,createdTime,modifiedTime,size,md5Checksum,headRevisionId,webViewLink,owners(displayName,emailAddress),trashed,imageMediaMetadata(width,height),videoMediaMetadata(width,height,durationMillis)';

    public function getFile(string $id): ?array;

    /** @return iterable<array> filhos diretos (pastas e arquivos) */
    public function listChildren(string $folderId): iterable;

    public function getStartPageToken(): string;

    /**
     * @return array{changes: array<array>, nextPageToken: ?string, newStartPageToken: ?string}
     */
    public function listChanges(string $pageToken): array;

    /** Baixa o conteúdo do arquivo para um caminho local. */
    public function download(string $id, string $destination): void;

    /** Baixa a miniatura que o Drive gera (false se ainda não existe). */
    public function downloadThumbnail(string $id, string $destination): bool;
}
