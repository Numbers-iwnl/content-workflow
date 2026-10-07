<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'role', 'review_order'])]
#[Hidden(['password', 'remember_token', 'login_token_hash', 'login_token_encrypted'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isApprover(): bool
    {
        return $this->review_order !== null;
    }

    /** Aprovadoras na ordem em que revisam (Bruna, depois Carla). */
    public function scopeApprovers(Builder $query): void
    {
        $query->whereNotNull('review_order')->orderBy('review_order');
    }

    /**
     * Gera um novo link pessoal de acesso e invalida o anterior.
     * Retorna o token em texto puro (só existe neste momento).
     */
    public function issueLoginToken(): string
    {
        $token = Str::random(40);
        $this->forceFill([
            'login_token_hash' => hash('sha256', $token),
            'login_token_encrypted' => Crypt::encryptString($token),
        ])->save();

        return $token;
    }

    /**
     * O link pessoal atual, para os avisos de WhatsApp. Nulo se o link foi
     * gerado antes de o sistema guardar a cópia (aí é preciso gerar um novo).
     */
    public function loginUrl(): ?string
    {
        if (! $this->login_token_encrypted) {
            return null;
        }

        try {
            return route('login.link', Crypt::decryptString($this->login_token_encrypted));
        } catch (DecryptException) {
            return null;
        }
    }

    public static function findByLoginToken(string $token): ?self
    {
        return static::where('login_token_hash', hash('sha256', $token))->first();
    }
}
