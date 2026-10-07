<?php

namespace App\Services\Drive;

use App\Enums\Account;
use App\Enums\ContentType;
use App\Enums\Cta;

/**
 * Aplica as regras de config/conteudo.php a um arquivo do Drive: decide se
 * ele é ignorado e sugere os campos do conteúdo a partir do caminho e do nome.
 *
 * $path é a lista de nomes de pasta da pasta monitorada até a pasta do
 * arquivo, ex.: ['VÍDEOS PARA CONFERÊNCIA', '2026', '2026 - VIDEOMAKER 01 - RENATO'].
 */
class ContentRules
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('conteudo');
    }

    /** Motivo para ignorar o arquivo, ou null se ele deve virar conteúdo. */
    public function ignoreReason(array $file, array $path): ?string
    {
        $mime = $file['mimeType'] ?? '';
        $name = $file['name'] ?? '';

        if (! collect($this->config['mime_prefixes'])->contains(fn ($prefix) => str_starts_with($mime, $prefix))) {
            return "tipo de arquivo ({$mime})";
        }

        $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, $this->config['ignore_extensions'], true)) {
            return "extensão .{$extension}";
        }

        // A pasta monitorada em si nunca bloqueia (ex.: "VÍDEOS PARA CONFERÊNCIA").
        foreach (array_slice($path, 1) as $folder) {
            foreach ($this->config['ignore_folder_patterns'] as $pattern) {
                if (preg_match($pattern, trim($folder))) {
                    return "pasta \"{$folder}\"";
                }
            }
        }

        foreach ($this->config['ignore_file_patterns'] as $pattern) {
            if (preg_match($pattern, $name)) {
                return 'nome do arquivo';
            }
        }

        return null;
    }

    /** Imagens dentro de pastas de carrossel são agrupadas pela pasta-mãe. */
    public function isCarouselImage(array $file, array $path): bool
    {
        if (! str_starts_with($file['mimeType'] ?? '', 'image/')) {
            return false;
        }

        return preg_match($this->config['carousel_file_pattern'], $file['name'] ?? '')
            || collect(array_slice($path, 1))->contains(fn ($folder) => preg_match($this->config['carousel_folder_pattern'], $folder));
    }

    /** Campos sugeridos para um conteúdo novo. */
    public function suggest(array $file, array $path, ?Account $defaultAccount, bool $carousel): array
    {
        $name = $file['name'] ?? '';
        $folders = array_slice($path, 1);

        return [
            'title' => $carousel ? trim(end($path)) : self::stripExtension($name),
            'type' => match (true) {
                $carousel => ContentType::Carrossel,
                str_starts_with($file['mimeType'] ?? '', 'video/') => ContentType::Reel,
                default => ContentType::Estatico,
            },
            'account' => $this->account($path) ?? $defaultAccount,
            'cta' => $this->cta(implode('/', $folders).'/'.$name),
            'produced_by' => $this->producer($folders, $file),
            'project' => $this->project($folders, $carousel),
        ];
    }

    private function account(array $path): ?Account
    {
        $joined = implode('/', $path);

        foreach ($this->config['account_rules'] as $rule) {
            if (preg_match($rule['pattern'], $joined)) {
                return Account::from($rule['account']);
            }
        }

        return null;
    }

    private function cta(string $haystack): ?Cta
    {
        foreach ($this->config['cta_patterns'] as $cta => $pattern) {
            if (preg_match($pattern, $haystack)) {
                return Cta::from($cta);
            }
        }

        return null;
    }

    private function producer(array $folders, array $file): ?string
    {
        foreach (array_reverse($folders) as $folder) {
            foreach ($this->config['producer_folder_rules'] as $rule) {
                if (preg_match($rule['pattern'], trim($folder), $match)) {
                    return $rule['name'] ?? mb_convert_case(trim($match[1]), MB_CASE_TITLE);
                }
            }
        }

        $owner = $file['owners'][0] ?? null;
        if (! $owner) {
            return null;
        }

        $email = $owner['emailAddress'] ?? '';

        return array_key_exists($email, $this->config['people'])
            ? $this->config['people'][$email]
            : $owner['displayName'] ?? null;
    }

    private function project(array $folders, bool $carousel): ?string
    {
        // No carrossel, a última pasta é o próprio post (vira o título).
        if ($carousel) {
            array_pop($folders);
        }

        foreach (array_reverse($folders) as $folder) {
            $folder = trim($folder);
            $structural = collect($this->config['project_skip_patterns'])
                ->contains(fn ($pattern) => preg_match($pattern, $folder));

            if (! $structural) {
                return $folder;
            }
        }

        return null;
    }

    public static function stripExtension(string $name): string
    {
        return preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name);
    }
}
