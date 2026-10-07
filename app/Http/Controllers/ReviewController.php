<?php

namespace App\Http\Controllers;

use App\Enums\ReviewDecision;
use App\Jobs\CacheDriveFile;
use App\Models\Content;
use App\Models\User;
use App\Services\ContentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Hub de aprovação, pensado para o celular da Bruna e da Carla. Cada uma
 * tem a própria fila; não existe ordem entre elas.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ContentWorkflow $workflow) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('review.index', [
            'queue' => $this->queue($user)->with('files')->get(),
            'recent' => Content::whereHas('reviews', fn ($q) => $q->where('user_id', $user->id))
                ->with(['reviews' => fn ($q) => $q->where('user_id', $user->id)])
                ->latest('updated_at')
                ->take(10)
                ->get(),
        ]);
    }

    public function show(Request $request, Content $content)
    {
        $content->load(['files', 'reviews.user', 'reviews.attachments']);
        $content->files->filter->needsCaching()->each(fn ($file) => CacheDriveFile::dispatch($file->id));

        $user = $request->user();
        $queue = $this->queue($user)->pluck('id');

        return view('review.show', [
            'content' => $content,
            'canReview' => $user->isApprover() && $content->status->reviewable() && $content->needs_approval,
            'mine' => $content->decisions()->get($user->id),
            'position' => $queue->search($content->id),
            'queueSize' => $queue->count(),
        ]);
    }

    public function store(Request $request, Content $content)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            // Chrome grava áudio como webm (às vezes detectado como vídeo), Safari como mp4.
            'audio' => ['nullable', 'file', 'max:20480', 'mimetypes:audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/x-m4a,audio/aac,audio/wav,video/webm,video/mp4,application/octet-stream'],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['image', 'max:15360'],
        ]);

        $dir = "reviews/{$content->id}";
        $audioPath = $request->file('audio')?->store($dir);
        $attachments = collect($request->file('images', []))
            ->map(fn (UploadedFile $image) => ['path' => $image->store($dir), 'mime_type' => $image->getMimeType()])
            ->all();

        try {
            $decision = ReviewDecision::from($data['decision']);
            $this->workflow->review($content, $request->user(), $decision, $data['note'] ?? null, $audioPath, $attachments);
        } catch (InvalidArgumentException $e) {
            return $request->wantsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withInput()->withErrors($e->getMessage());
        }

        $message = match ($decision) {
            ReviewDecision::Aprovado => 'Aprovado ✓',
            ReviewDecision::Ajuste => 'Pedido de correção enviado para a equipe',
            ReviewDecision::Reprovado => 'Reprovado',
        };

        $next = $this->queue($request->user())->first();
        $target = $next ? route('review.show', $next) : route('review.index');
        session()->flash('status', $message);

        // O painel de ajuste envia por fetch (para levar o áudio gravado).
        return $request->wantsJson()
            ? response()->json(['redirect' => $target])
            : redirect($target);
    }

    /** O que espera esta pessoa, do mais antigo para o mais novo. */
    private function queue(User $user)
    {
        return Content::awaiting($user)->orderBy('sent_for_review_at')->orderBy('id');
    }
}
