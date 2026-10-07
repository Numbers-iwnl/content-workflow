<?php

namespace App\Services\Drive;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente do Drive autenticado como conta de serviço (JWT assinado com a
 * chave do JSON). Só leitura: a conta enxerga apenas as pastas que foram
 * compartilhadas com o e-mail dela.
 */
class GoogleDrive implements DriveApi
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    public function __construct(private readonly array $credentials) {}

    public static function fromConfig(): self
    {
        $path = config('services.google.service_account');

        // Caminho relativo: "storage/..." segue a pasta de dados (conteudos-data no servidor).
        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Z]:/i', $path)) {
            $path = str_starts_with($path, 'storage/') ? storage_path(substr($path, 8)) : base_path($path);
        }

        if (! is_file($path)) {
            throw new RuntimeException("Chave da conta de serviço não encontrada em {$path}");
        }

        return new self(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    }

    public function getFile(string $id): ?array
    {
        $response = $this->http()->get(self::API."/files/{$id}", [
            'fields' => self::FILE_FIELDS,
            'supportsAllDrives' => 'true',
        ]);

        if ($response->notFound()) {
            return null;
        }

        return $response->throw()->json();
    }

    public function listChildren(string $folderId): iterable
    {
        $pageToken = null;

        do {
            $response = $this->http()->get(self::API.'/files', array_filter([
                'q' => "'{$folderId}' in parents and trashed = false",
                'fields' => 'nextPageToken,files('.self::FILE_FIELDS.')',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]))->throw()->json();

            yield from $response['files'] ?? [];
            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    public function getStartPageToken(): string
    {
        return $this->http()->get(self::API.'/changes/startPageToken', [
            'supportsAllDrives' => 'true',
        ])->throw()->json('startPageToken');
    }

    public function listChanges(string $pageToken): array
    {
        $response = $this->http()->get(self::API.'/changes', [
            'pageToken' => $pageToken,
            'fields' => 'nextPageToken,newStartPageToken,changes(removed,fileId,file('.self::FILE_FIELDS.'))',
            'pageSize' => 1000,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ])->throw()->json();

        return [
            'changes' => $response['changes'] ?? [],
            'nextPageToken' => $response['nextPageToken'] ?? null,
            'newStartPageToken' => $response['newStartPageToken'] ?? null,
        ];
    }

    public function download(string $id, string $destination): void
    {
        $this->http()
            ->timeout(600)
            ->sink($destination)
            ->get(self::API."/files/{$id}", ['alt' => 'media', 'supportsAllDrives' => 'true'])
            ->throw();
    }

    public function downloadThumbnail(string $id, string $destination): bool
    {
        $link = $this->http()->get(self::API."/files/{$id}", [
            'fields' => 'thumbnailLink',
            'supportsAllDrives' => 'true',
        ])->json('thumbnailLink');

        if (! $link) {
            return false; // vídeo recém-enviado: o Drive ainda está processando
        }

        // O link vem com =s220 (220px); pedimos uma versão maior.
        $link = preg_replace('/=s\d+$/', '=s1000', $link);

        return Http::withToken($this->accessToken())->timeout(60)->sink($destination)->get($link)->successful();
    }

    private function http(): PendingRequest
    {
        // Repete só em falhas passageiras (rede, limite de taxa, erro do Google).
        $transient = fn ($e) => ! $e instanceof RequestException
            || in_array($e->response->status(), [429, 500, 502, 503, 504], true);

        return Http::withToken($this->accessToken())->acceptJson()->timeout(60)->retry(3, 1000, $transient, throw: false);
    }

    private function accessToken(): string
    {
        return Cache::remember('google-drive-token:'.$this->credentials['client_email'], 3000, function () {
            return Http::asForm()->post($this->credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt(),
            ])->throw()->json('access_token');
        });
    }

    private function signedJwt(): string
    {
        $now = time();
        $segments = [
            self::base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64url(json_encode([
                'iss' => $this->credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $this->credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        if (! openssl_sign(implode('.', $segments), $signature, $this->credentials['private_key'], 'sha256WithRSAEncryption')) {
            throw new RuntimeException('Não foi possível assinar o JWT da conta de serviço.');
        }

        $segments[] = self::base64url($signature);

        return implode('.', $segments);
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
