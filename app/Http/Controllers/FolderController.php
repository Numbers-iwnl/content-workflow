<?php

namespace App\Http\Controllers;

use App\Enums\Account;
use App\Jobs\BackfillDriveFiles;
use App\Jobs\BaselineWatchedFolder;
use App\Models\Setting;
use App\Models\WatchedFolder;
use App\Services\Drive\DriveSync;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FolderController extends Controller
{
    public function index()
    {
        return view('folders.index', [
            'folders' => WatchedFolder::withCount([
                'files as imported_count' => fn ($q) => $q->where('state', 'imported'),
                'files as ignored_count' => fn ($q) => $q->where('state', 'ignored'),
            ])->orderBy('name')->get(),
            'serviceAccount' => config('services.google.client_email'),
            'lastSync' => ($at = Setting::get('drive.last_sync_at')) ? Carbon::parse($at) : null,
            'lastError' => json_decode(Setting::get('drive.last_error') ?? 'null', true),
            'lastBackfill' => json_decode(Setting::get('drive.last_backfill') ?? 'null', true),
        ]);
    }

    public function store(Request $request, DriveSync $sync)
    {
        $data = $request->validate([
            'link' => ['required', 'string', 'max:500'],
            'account' => ['nullable', Rule::enum(Account::class)],
        ]);

        try {
            $folder = $sync->register($data['link'], isset($data['account']) ? Account::from($data['account']) : null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors($e->getMessage());
        }

        BaselineWatchedFolder::dispatch($folder->id);

        return back()->with('status', "\"{$folder->name}\" cadastrada. O sistema está registrando o que já existe nela; em alguns minutos ela começa a ser monitorada.");
    }

    /** Traz para a caixa de entrada o que já estava nas pastas desde uma data. */
    public function backfill(Request $request)
    {
        $data = $request->validate(['desde' => ['required', 'date', 'before_or_equal:today']]);

        BackfillDriveFiles::dispatch($data['desde']);

        return back()->with('status', 'Buscando nas pastas o que foi criado desde '.Carbon::parse($data['desde'])->format('d/m/Y').'. Em alguns minutos aparece na caixa de entrada.');
    }

    public function toggle(WatchedFolder $folder)
    {
        $folder->update(['active' => ! $folder->active]);

        return back()->with('status', $folder->active ? 'Pasta reativada.' : 'Pasta pausada: novos arquivos dela não entram mais.');
    }
}
