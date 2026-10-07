import Alpine from 'alpinejs';

/**
 * Painel de correção / reprovação do hub de aprovação: texto, áudio e
 * prints, todos opcionais (basta um). Envia por fetch com FormData, assim o
 * áudio gravado e as imagens vão direto, sem depender de truques com
 * <input type="file"> que falham em alguns celulares.
 */
Alpine.data('reviewSheet', (initialSheet = null, initialNote = '') => {
    // Fora do estado reativo: objetos do navegador que o Alpine não deve embrulhar.
    let recorder = null;
    let stream = null;
    let chunks = [];
    let audioBlob = null;
    let timer = null;

    return {
        sheet: initialSheet,
        note: initialNote,
        rec: 'idle', // idle | recording | done | unsupported
        seconds: 0,
        audioUrl: null,
        images: [], // { url, name }
        files: [],
        sending: false,
        error: null,

        init() {
            if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
                this.rec = 'unsupported';
            }
            this.$el.addEventListener('paste', (e) => {
                const pasted = [...(e.clipboardData?.files || [])].filter((f) => f.type.startsWith('image/'));
                if (pasted.length) {
                    e.preventDefault();
                    this.addImages(pasted);
                }
            });
        },

        chip(text) {
            this.note = this.note.trim() ? `${this.note.trimEnd()}\n${text}: ` : `${text}: `;
            this.$nextTick(() => {
                this.$refs.note.focus();
                this.$refs.note.setSelectionRange(this.note.length, this.note.length);
            });
        },

        async start() {
            this.error = null;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                chunks = [];
                recorder = new MediaRecorder(stream);
                recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
                recorder.onstop = () => this.finishRecording();
                recorder.start();
                this.seconds = 0;
                timer = setInterval(() => this.seconds++, 1000);
                this.rec = 'recording';
            } catch {
                this.error = 'Não foi possível acessar o microfone. Verifique a permissão do navegador.';
            }
        },

        stop() {
            clearInterval(timer);
            if (recorder && recorder.state !== 'inactive') {
                recorder.stop();
                // Garantia: se o navegador demorar a avisar que parou, fecha mesmo assim.
                setTimeout(() => this.rec === 'recording' && this.finishRecording(), 1500);
            } else {
                this.finishRecording();
            }
        },

        finishRecording() {
            if (this.rec !== 'recording') return;
            this.rec = 'done'; // primeiro muda a tela: o botão sai do vermelho na hora
            stream?.getTracks().forEach((t) => t.stop());
            const type = recorder?.mimeType || chunks[0]?.type || 'audio/webm';
            audioBlob = new Blob(chunks, { type });
            this.audioUrl = URL.createObjectURL(audioBlob);
        },

        discardAudio() {
            this.audioUrl && URL.revokeObjectURL(this.audioUrl);
            this.audioUrl = null;
            audioBlob = null;
            this.rec = 'idle';
        },

        pick(event) {
            this.addImages([...event.target.files]);
            event.target.value = '';
        },

        addImages(list) {
            list.slice(0, 6 - this.files.length).forEach((file) => {
                this.files.push(file);
                this.images.push({ url: URL.createObjectURL(file), name: file.name });
            });
        },

        removeImage(index) {
            URL.revokeObjectURL(this.images[index].url);
            this.images.splice(index, 1);
            this.files.splice(index, 1);
        },

        get clock() {
            return `${Math.floor(this.seconds / 60)}:${String(this.seconds % 60).padStart(2, '0')}`;
        },

        async submit(form) {
            if (this.rec === 'recording') this.stop();
            if (!this.note.trim() && !audioBlob && !this.files.length) {
                this.error = 'Escreva, grave um áudio ou anexe um print. Qualquer um dos três basta.';
                return;
            }

            this.sending = true;
            this.error = null;
            const body = new FormData(form);
            if (audioBlob) {
                const ext = audioBlob.type.includes('mp4') ? 'm4a' : audioBlob.type.includes('ogg') ? 'ogg' : 'webm';
                body.append('audio', audioBlob, `audio.${ext}`);
            }
            this.files.forEach((file) => body.append('images[]', Alpine.raw(file)));

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }
                this.error = data.message || 'Não foi possível enviar. Tente de novo.';
            } catch {
                this.error = 'Sem conexão. Tente de novo.';
            }
            this.sending = false;
        },
    };
});

/**
 * A planilha de conteúdos: filtros por coluna (como no Google Sheets),
 * ordenação e colunas que se arrastam. Ordem das colunas e filtros ficam
 * salvos no navegador de quem usa.
 */
const storage = {
    get(key, fallback) {
        try {
            return JSON.parse(localStorage.getItem(key)) ?? fallback;
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch {}
    },
};

const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const decisionClass = {
    Aprovado: 'bg-emerald-500/15 text-emerald-800',
    Correção: 'bg-amber-500/15 text-amber-800',
    Reprovado: 'bg-red-500/12 text-red-700',
};

const statusClass = {
    Corrigido: 'bg-cyan-500/15 text-cyan-800',
    Agendado: 'bg-verde/15 text-verde-dk',
    Postado: 'bg-carbon text-white',
};

Alpine.data('sheet', (rows, columns) => ({
    rows,
    columns,
    order: [],
    hidden: {}, // coluna → valores escondidos pelo filtro
    sort: { key: 'created', dir: 'desc' },
    search: '',
    open: null,
    dragging: null,
    selected: [], // ids marcados (para enviar à aprovação)

    init() {
        const keys = columns.map((c) => c.key);
        const saved = storage.get('planilha.order', []).filter((k) => keys.includes(k));
        this.order = [...saved, ...keys.filter((k) => !saved.includes(k))];
        this.hidden = storage.get('planilha.filters', {});
        this.sort = storage.get('planilha.sort', this.sort);
        this.$watch('order', (v) => storage.set('planilha.order', v));
        this.$watch('hidden', (v) => storage.set('planilha.filters', v), { deep: true });
        this.$watch('sort', (v) => storage.set('planilha.sort', v), { deep: true });
    },

    column(key) {
        return this.columns.find((c) => c.key === key);
    },

    value(row, key) {
        if (key.startsWith('u')) return row.decisions[key];
        return row[key];
    },

    sortValue(row, key) {
        if (key === 'created') return row.created ?? '';
        if (key === 'status') return `${row.status} ${row.scheduledSort ?? ''}`;
        return String(this.value(row, key) ?? '').toLocaleLowerCase('pt-BR');
    },

    get visible() {
        const term = this.search.trim().toLocaleLowerCase('pt-BR');
        const result = this.rows.filter((row) => {
            if (term && !row.title.toLocaleLowerCase('pt-BR').includes(term)) return false;
            return Object.entries(this.hidden).every(([key, values]) => !values.length || !values.includes(this.value(row, key)));
        });
        const { key, dir } = this.sort;
        return result.sort((a, b) => {
            const cmp = this.sortValue(a, key).localeCompare(this.sortValue(b, key), 'pt-BR', { numeric: true });
            return dir === 'asc' ? cmp : -cmp;
        });
    },

    options(key) {
        const counts = {};
        this.rows.forEach((row) => {
            const v = this.value(row, key);
            counts[v] = (counts[v] ?? 0) + 1;
        });
        return Object.entries(counts).sort(([a], [b]) => a.localeCompare(b, 'pt-BR'));
    },

    isShown(key, value) {
        return !(this.hidden[key] ?? []).includes(value);
    },

    toggle(key, value) {
        const current = this.hidden[key] ?? [];
        this.hidden = {
            ...this.hidden,
            [key]: current.includes(value) ? current.filter((v) => v !== value) : [...current, value],
        };
    },

    only(key, value) {
        this.hidden = { ...this.hidden, [key]: this.options(key).map(([v]) => v).filter((v) => v !== value) };
    },

    clearFilter(key) {
        this.hidden = { ...this.hidden, [key]: [] };
    },

    filtered(key) {
        return (this.hidden[key] ?? []).length > 0;
    },

    get anyFilter() {
        return Object.values(this.hidden).some((v) => v.length) || this.search;
    },

    clearAll() {
        this.hidden = {};
        this.search = '';
    },

    resetColumns() {
        this.order = this.columns.map((c) => c.key);
    },

    get allSelected() {
        return this.visible.length > 0 && this.visible.every((row) => this.selected.includes(row.id));
    },

    toggleAll() {
        const ids = this.visible.map((row) => row.id);
        this.selected = this.allSelected
            ? this.selected.filter((id) => !ids.includes(id))
            : [...new Set([...this.selected, ...ids])];
    },

    sortBy(key, dir) {
        this.sort = { key, dir };
        this.open = null;
    },

    drop(target) {
        if (!this.dragging || this.dragging === target) return;
        const order = this.order.filter((k) => k !== this.dragging);
        order.splice(order.indexOf(target), 0, this.dragging);
        this.order = order;
        this.dragging = null;
    },

    cell(row, key) {
        const v = this.value(row, key);
        const pill = (text, cls) => `<span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ${cls}">${escapeHtml(text)}</span>`;

        switch (key) {
            case 'created':
                return `<span class="tabular-nums text-slate">${escapeHtml(row.createdLabel)}</span>`;
            case 'title':
                return `<a href="${escapeHtml(row.url)}" class="font-medium text-ink underline decoration-silver underline-offset-4 hover:decoration-verde">${escapeHtml(v)}</a>`;
            case 'account':
                return row.accountColor
                    ? `<span class="inline-flex items-center gap-2 whitespace-nowrap"><span class="size-2.5 rounded-full" style="background:${escapeHtml(row.accountColor)}"></span>${escapeHtml(v)}</span>`
                    : '<span class="text-slate/50">—</span>';
            case 'caption':
                return v === 'Com legenda' ? pill('✓ Sim', 'bg-verde/15 text-verde-dk') : pill('✗ Não', 'bg-amber-500/15 text-amber-800');
            case 'status':
                if (v === 'Vazio') return '<span class="text-xs text-slate/50">vazio</span>';
                return pill(row.scheduled && v === 'Agendado' ? `Agendado · ${row.scheduled}` : v, statusClass[v] ?? 'bg-paper-2 text-slate');
            default:
                if (key.startsWith('u')) {
                    if (v === 'Não enviado') return '<span class="text-xs text-slate/40">não enviado</span>';
                    if (v === 'Aguardando') return pill('Aguardando', 'bg-paper-2 text-slate');
                    return pill(v, decisionClass[v] ?? '');
                }
                return v === '—' ? '<span class="text-slate/50">—</span>' : `<span class="whitespace-nowrap">${escapeHtml(v)}</span>`;
        }
    },
}));

Alpine.data('copy', (text) => ({
    copied: false,
    async copy() {
        await navigator.clipboard.writeText(text);
        this.copied = true;
        setTimeout(() => (this.copied = false), 2000);
    },
}));

window.Alpine = Alpine;
Alpine.start();
