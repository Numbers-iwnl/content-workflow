<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PeopleController extends Controller
{
    /** Pessoas, links de acesso e os avisos de WhatsApp (componente whatsapp-notices). */
    public function index()
    {
        return view('people.index', [
            'users' => User::orderByRaw('review_order is null')->orderBy('review_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        // A ordem só decide a posição da coluna na planilha (não há fila entre elas).
        $data['review_order'] = $data['role'] === Role::Aprovadora->value
            ? (User::max('review_order') ?? 0) + 1
            : null;

        User::create($data);

        return back()->with('status', "Pessoa cadastrada: {$data['name']}. Gere o link de acesso na lista abaixo.");
    }

    /** Gera um link novo (o anterior para de funcionar) e mostra uma única vez. */
    public function link(User $user)
    {
        $url = route('login.link', $user->issueLoginToken());

        return back()->with('link', [
            'user' => $user->name,
            'url' => $url,
            'message' => "Oi, {$user->name}! Este é o seu link de acesso ao sistema de conteúdos (é pessoal, não compartilhe): {$url}",
        ]);
    }
}
