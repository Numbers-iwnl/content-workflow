<?php

namespace App\Http\Controllers;

use App\Enums\Account;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\Cta;
use App\Jobs\CacheDriveFile;
use App\Models\Content;
use App\Models\User;
use App\Services\ContentWorkflow;
use App\Support\Pipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ContentController extends Controller
{
    public function __construct(private readonly ContentWorkflow $workflow) {}

    public function index(Request $request)
    {
        $stage = array_key_exists($request->query('etapa'), Pipeline::STAGES) ? $request->query('etapa') : 'entrada';

        if ($stage === 'planilha') {
            return $this->planilha();
        }

        $contents = Pipeline::query($stage)
            ->when($request->query('conta'), fn ($q, $account) => $q->where('account', $account))
            ->when($request->query('busca'), fn ($q, $term) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('project', 'like', "%{$term}%")
                ->orWhere('caption', 'like', "%{$term}%")))
            ->with(['files', 'reviews.user', 'reviews.attachments', 'suggestedCorrectionOf'])
            ->paginate(48)
            ->withQueryString();

        return view('contents.index', [
            'stage' => $stage,
            'contents' => $contents,
            'selectable' => $stage === 'entrada',
            'approvers' => User::approvers()->get(),
        ]);
    }

    /** A planilha: uma linha por conteúdo, montada no navegador (filtros e colunas). */
    private function planilha()
    {
        $approvers = User::approvers()->get();

        $rows = Pipeline::query('planilha')->with(['reviews', 'files'])->get()->map(function (Content $content) use ($approvers) {
            $decisions = $content->decisions();

            return [
                'id' => $content->id,
                'approval' => $content->needs_approval,
                'url' => route('contents.show', $content),
                'created' => $content->drive_created_at?->format('Y-m-d H:i'),
                'createdLabel' => $content->drive_created_at?->format('d/m/Y'),
                'account' => $content->account?->label() ?? '—',
                'accountColor' => $content->account?->color(),
                'type' => $content->type?->label() ?? '—',
                'cta' => $content->cta?->label() ?? '—',
                'title' => $content->title,
                'caption' => filled($content->caption) ? 'Com legenda' : 'Sem legenda',
                'producer' => $content->produced_by ?? '—',
                'decisions' => $approvers->mapWithKeys(fn (User $u) => [
                    'u'.$u->id => $content->needs_approval
                        ? $decisions->get($u->id)?->decision->short() ?? 'Aguardando'
                        : 'Não enviado',
                ]),
                'status' => $content->status === ContentStatus::Pendente ? 'Vazio' : $content->status->label(),
                'scheduled' => $content->scheduled_for?->format('d/m H:i'),
                'scheduledSort' => $content->scheduled_for?->format('Y-m-d H:i'),
            ];
        });

        return view('contents.planilha', [
            'rows' => $rows,
            'approvers' => $approvers,
        ]);
    }

    /** Na planilha: envia (ou tira) vários conteúdos da aprovação de uma vez. */
    public function approvalMany(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'acao' => ['required', Rule::in(['enviar', 'retirar'])],
        ]);

        $contents = Content::whereIn('id', $data['ids'])->whereIn('status', ContentStatus::planilha())->get();

        foreach ($contents as $content) {
            $data['acao'] === 'enviar'
                ? $this->workflow->requestApproval($content, $request->user())
                : $this->workflow->cancelApproval($content, $request->user());
        }

        $n = $contents->count();
        $message = $data['acao'] === 'enviar'
            ? ($n === 1 ? '1 conteúdo enviado para a Bruna e a Carla.' : "{$n} conteúdos enviados para a Bruna e a Carla.")
            : ($n === 1 ? '1 conteúdo saiu da aprovação.' : "{$n} conteúdos saíram da aprovação.");

        return back()->with('status', $message)->with('notify', $data['acao'] === 'enviar');
    }

    /** Leva vários conteúdos da caixa de entrada para a planilha de uma vez. */
    public function moveMany(Request $request)
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        $moved = 0;
        $skipped = [];

        foreach (Content::whereIn('id', $request->input('ids'))->get() as $content) {
            if ($this->workflow->moveToPlanilha($content, $request->user())) {
                $moved++;
            } else {
                $skipped[] = "{$content->title}: falta ".implode(', ', $content->missingForPlanilha()).'.';
            }
        }

        $message = $moved === 1 ? '1 conteúdo foi para a planilha.' : "{$moved} conteúdos foram para a planilha.";

        return redirect()->route('contents.index', ['etapa' => $moved ? 'planilha' : 'entrada'])
            ->with('status', $moved ? $message : null)
            ->withErrors($skipped);
    }

    public function show(Content $content)
    {
        $content->load(['files', 'reviews.user', 'reviews.attachments', 'events.user', 'suggestedCorrectionOf']);

        // Garante que a prévia vai estar pronta (o job ignora se já estiver).
        $content->files->filter->needsCaching()->each(fn ($file) => CacheDriveFile::dispatch($file->id));

        $stage = Pipeline::stageOf($content->status);
        $siblings = Pipeline::query($stage)->pluck('id');
        $position = $siblings->search($content->id);

        return view('contents.show', [
            'content' => $content,
            'stage' => $stage,
            'position' => $position,
            'total' => $siblings->count(),
            'previousId' => $position > 0 ? $siblings[$position - 1] : null,
            'nextId' => $position !== false ? $siblings[$position + 1] ?? null : null,
            'approvers' => User::approvers()->get(),
            'projects' => Content::whereNotNull('project')->distinct()->orderBy('project')->pluck('project'),
            'producers' => Content::whereNotNull('produced_by')->distinct()->orderBy('produced_by')->pluck('produced_by'),
        ]);
    }

    public function update(Request $request, Content $content)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'account' => ['nullable', Rule::enum(Account::class)],
            'collab_accounts' => ['nullable', 'array'],
            'collab_accounts.*' => [Rule::enum(Account::class)],
            'type' => ['nullable', Rule::enum(ContentType::class)],
            'cta' => ['nullable', Rule::enum(Cta::class)],
            'project' => ['nullable', 'string', 'max:255'],
            'produced_by' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:2200'], // limite do Instagram
        ]);

        // Próximo da caixa de entrada, calculado antes de o conteúdo sair dela.
        $nextId = $this->nextInStage($content);

        $data['collab_accounts'] = array_values(array_diff($data['collab_accounts'] ?? [], [$data['account'] ?? null])) ?: null;
        $content->fill($data);

        if ($content->isDirty()) {
            $changed = array_keys($content->getDirty());
            $content->save();
            $this->workflow->log($content, 'editado', $request->user(), data: ['campos' => $changed]);
        }

        // Área, tipo e CTA preenchidos: vai sozinho para a planilha.
        if ($this->workflow->moveToPlanilha($content, $request->user())) {
            $message = "“{$content->title}” foi para a planilha.";

            return $nextId
                ? redirect()->route('contents.show', $nextId)->with('status', $message.' Este é o próximo da caixa de entrada.')
                : redirect()->route('contents.index', ['etapa' => 'planilha'])->with('status', $message.' Caixa de entrada zerada!');
        }

        return back()->with('status', 'Salvo.');
    }

    public function action(Request $request, Content $content, string $action)
    {
        $user = $request->user();

        // Depois de arquivar, a Ana continua onde estava (próximo da mesma aba).
        $stage = Pipeline::stageOf($content->status);
        $nextId = $this->nextInStage($content);

        try {
            match ($action) {
                'arquivar' => $this->workflow->archive($content, $user, $request->input('motivo')),
                'pedir-aprovacao' => $this->workflow->requestApproval($content, $user),
                'retirar-aprovacao' => $this->workflow->cancelApproval($content, $user),
                'reverter' => $request->boolean('confirmo')
                    ? $this->workflow->revertRejection($content, $user)
                    : throw new InvalidArgumentException('Confirme que quer reverter a reprovação.'),
                'restaurar' => $this->workflow->restore($content, $user),
                'excluir' => $this->confirmAndDestroy($request, $content),
                'corrigido' => $this->workflow->markCorrected($content, $user),
                'vincular' => $this->linkCorrection($content, $user),
                'agendar' => $this->workflow->schedule($content, $this->scheduleDate($request), $user),
                'desagendar' => $this->workflow->unschedule($content, $user),
                'postado' => $this->workflow->markPosted($content, $user),
                default => abort(404),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withErrors($e->getMessage());
        }

        if ($action === 'excluir') {
            return redirect()->route('contents.index', ['etapa' => 'arquivados'])->with('status', "“{$content->title}” foi excluído do sistema.");
        }

        if ($action === 'arquivar') {
            return ($nextId ? redirect()->route('contents.show', $nextId) : redirect()->route('contents.index', ['etapa' => $stage]))
                ->with('status', "“{$content->title}” foi arquivado.");
        }

        if ($action === 'restaurar') {
            return back()->with('status', "“{$content->title}” voltou para a caixa de entrada.");
        }

        $messages = [
            'pedir-aprovacao' => 'Enviado para a Bruna e a Carla.',
            'retirar-aprovacao' => 'Saiu da aprovação.',
            'reverter' => 'Reprovação revertida. O conteúdo voltou para a aprovação, do zero.',
            'corrigido' => 'Marcado como corrigido. Ele volta para quem pediu a correção.',
            'vincular' => 'Arquivo novo vinculado como correção.',
            'agendar' => 'Agendado.',
            'desagendar' => 'Agendamento removido.',
            'postado' => 'Marcado como postado.',
        ];

        // Depois de vincular, o conteúdo duplicado deixou de existir.
        $target = $action === 'vincular' ? $content->suggested_correction_of_id : $content->id;

        return redirect()->route('contents.show', $target)
            ->with('status', $messages[$action])
            ->with('notify', $action === 'pedir-aprovacao');
    }

    /** Segunda verificação: o título precisa ser confirmado no formulário. */
    private function confirmAndDestroy(Request $request, Content $content): void
    {
        if ($request->input('confirmacao') !== 'EXCLUIR') {
            throw new InvalidArgumentException('Para excluir, digite EXCLUIR na confirmação.');
        }

        $this->workflow->destroy($content);
    }

    private function nextInStage(Content $content): ?int
    {
        $siblings = Pipeline::query(Pipeline::stageOf($content->status))->pluck('id');
        $position = $siblings->search($content->id);

        return $position === false ? null : $siblings[$position + 1] ?? null;
    }

    private function linkCorrection(Content $content, User $user): void
    {
        $original = $content->suggestedCorrectionOf
            ?? throw new InvalidArgumentException('Não há conteúdo sugerido para vincular.');

        $this->workflow->linkAsCorrection($content, $original, $user);
    }

    private function scheduleDate(Request $request): Carbon
    {
        $request->validate(['data' => ['required', 'date'], 'hora' => ['required', 'date_format:H:i']]);

        return Carbon::parse($request->input('data').' '.$request->input('hora'));
    }
}
