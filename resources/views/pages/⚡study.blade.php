<?php

use App\Models\StudySession;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Fokus')] class extends Component
{
    use WithPagination;

    #[Url(as: 'goal', except: null)]
    public ?int $goal_id = null;

    public int|string|null $topic_id = null;

    public string $studied_on = '';

    public int|string|null $minutes = null;

    public int|string|null $score = null;

    public string $notes = '';

    public ?int $editingId = null;

    public function mount(): void
    {
        $this->studied_on = today()->toDateString();

        if ($this->goal_id && ! auth()->user()->goals()->whereKey($this->goal_id)->exists()) {
            $this->goal_id = null;
        }

        $this->goal_id ??= $this->goals->first()?->id;
    }

    #[Computed]
    public function goals(): Collection
    {
        return auth()->user()->goals()->where('status', '!=', 'done')->orderBy('priority')->orderBy('name')->get();
    }

    #[Computed]
    public function topics(): Collection
    {
        if (! $this->goal_id) {
            return collect();
        }

        return auth()->user()->goals()->find($this->goal_id)?->topics()->get() ?? collect();
    }

    #[Computed]
    public function history(): LengthAwarePaginator
    {
        return auth()->user()->studySessions()
            ->with(['goal', 'topic'])
            ->latest('studied_on')
            ->latest('id')
            ->paginate(15);
    }

    public function updatedGoalId(): void
    {
        $this->topic_id = null;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'goal_id' => ['required', Rule::exists('goals', 'id')->where('user_id', auth()->id())->whereNull('deleted_at')],
            'topic_id' => ['nullable', Rule::exists('topics', 'id')->where('goal_id', $this->goal_id)],
            'studied_on' => ['required', 'date', 'before_or_equal:today'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'goal_id' => 'target',
            'studied_on' => 'tanggal',
            'minutes' => 'durasi',
            'score' => 'skor',
        ]);

        $attributes = [
            'goal_id' => $validated['goal_id'],
            'topic_id' => $validated['topic_id'] ?: null,
            'studied_on' => $validated['studied_on'],
            'minutes' => (int) $validated['minutes'],
            'score' => filled($validated['score']) ? $validated['score'] : null,
            'notes' => $validated['notes'] ?: null,
        ];

        if ($this->editingId) {
            auth()->user()->studySessions()->findOrFail($this->editingId)->update($attributes);
            $message = 'Sesi diperbarui';
        } else {
            auth()->user()->studySessions()->create($attributes);
            $message = 'Mantap! '.StudySession::formatMinutes($attributes['minutes']).' tercatat 💪';
        }

        $this->cancelEdit();
        $this->resetPage();
        $this->dispatch('toast', message: $message);
    }

    public function edit(int $sessionId): void
    {
        $session = auth()->user()->studySessions()->findOrFail($sessionId);

        $this->resetValidation();
        $this->editingId = $session->id;
        $this->goal_id = $session->goal_id;
        $this->topic_id = $session->topic_id;
        $this->studied_on = $session->studied_on->toDateString();
        $this->minutes = $session->minutes;
        $this->score = is_null($session->score) ? null : StudySession::formatScore($session->score);
        $this->notes = (string) $session->notes;

        $this->js("document.getElementById('log-form').scrollIntoView({ behavior: 'smooth' })");
    }

    public function cancelEdit(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'topic_id', 'minutes', 'score', 'notes');
        $this->studied_on = today()->toDateString();
    }

    public function delete(int $sessionId): void
    {
        auth()->user()->studySessions()->findOrFail($sessionId)->delete();

        if ($this->editingId === $sessionId) {
            $this->cancelEdit();
        }

        $this->dispatch('toast', message: 'Sesi dihapus');
    }
};
?>

<div class="space-y-5">
    <x-page-header title="Sesi Fokus" subtitle="Nyalakan timer, fokus, lalu catat hasilnya." icon="timer" />

    {{--
        Timer: Stopwatch atau Pomodoro. Status disimpan di localStorage agar tetap berjalan walau aplikasi ditutup.
        Pomodoro: fase fokus berakhir → istirahat otomatis dimulai; istirahat berakhir → menunggu pengguna memulai lagi.
    --}}
    <section class="card overflow-hidden text-center" wire:ignore x-data="{
        mode: 'stopwatch',
        now: Date.now(),
        swKey: 'prepare2027.timer',
        startedAt: null,
        accumulated: 0,
        pKey: 'prima.pomodoro',
        presets: { '25/5': [25, 5], '50/10': [50, 10], '15/3': [15, 3] },
        preset: '25/5',
        phase: 'focus',
        endsAt: null,
        remaining: null,
        focusDone: 0,
        cycles: 0,
        audio: null,
        init() {
            try {
                this.mode = localStorage.getItem('prima.timerMode') || 'stopwatch';
                const sw = JSON.parse(localStorage.getItem(this.swKey) || 'null');
                if (sw) { this.startedAt = sw.startedAt; this.accumulated = sw.accumulated || 0; }
                const p = JSON.parse(localStorage.getItem(this.pKey) || 'null');
                if (p) { Object.assign(this, p); }
            } catch (e) {}
            if (! this.presets[this.preset]) this.preset = '25/5';
            this.checkPhase();
            const tick = setInterval(() => { this.now = Date.now(); this.checkPhase(); }, 1000);
            document.addEventListener('livewire:navigating', () => clearInterval(tick), { once: true });
        },
        save() {
            try {
                localStorage.setItem('prima.timerMode', this.mode);
                (this.startedAt || this.accumulated)
                    ? localStorage.setItem(this.swKey, JSON.stringify({ startedAt: this.startedAt, accumulated: this.accumulated }))
                    : localStorage.removeItem(this.swKey);
                this.pActive
                    ? localStorage.setItem(this.pKey, JSON.stringify({ preset: this.preset, phase: this.phase, endsAt: this.endsAt, remaining: this.remaining, focusDone: this.focusDone, cycles: this.cycles }))
                    : localStorage.removeItem(this.pKey);
            } catch (e) {}
        },
        pad(n) { return String(n).padStart(2, '0') },
        get busy() { return this.elapsed > 0 || this.pActive },
        setMode(mode) { if (! this.busy) { this.mode = mode; this.save(); } },
        get running() { return this.startedAt !== null },
        get elapsed() { return this.accumulated + (this.running ? this.now - this.startedAt : 0) },
        get display() {
            const s = Math.floor(this.elapsed / 1000);
            return `${this.pad(Math.floor(s / 3600))}:${this.pad(Math.floor(s % 3600 / 60))}:${this.pad(s % 60)}`;
        },
        start() { this.unlockAudio(); this.now = Date.now(); this.startedAt = this.now; this.save(); },
        pause() { this.accumulated = this.elapsed; this.startedAt = null; this.save(); },
        finish() { this.record(this.elapsed); this.startedAt = null; this.accumulated = 0; this.save(); },
        async reset() { if (await window.primaConfirm('Reset timer? Waktu yang belum disimpan akan hilang.')) { this.startedAt = null; this.accumulated = 0; this.save(); } },
        get focusLen() { return this.presets[this.preset][0] * 60000 },
        get breakLen() { return this.presets[this.preset][1] * 60000 },
        get phaseLen() { return this.phase === 'focus' ? this.focusLen : this.breakLen },
        get pRunning() { return this.endsAt !== null },
        get pActive() { return this.pRunning || this.remaining !== null || this.focusDone > 0 || this.phase === 'break' },
        get pLeft() { return this.pRunning ? Math.max(0, this.endsAt - this.now) : (this.remaining ?? this.phaseLen) },
        get pProgress() { return 1 - this.pLeft / this.phaseLen },
        get pDisplay() { const s = Math.ceil(this.pLeft / 1000); return `${this.pad(Math.floor(s / 60))}:${this.pad(s % 60)}`; },
        get pFocusMs() { return this.focusDone + (this.phase === 'focus' ? this.phaseLen - this.pLeft : 0) },
        pStart() { this.unlockAudio(); const left = this.pLeft; this.now = Date.now(); this.endsAt = this.now + left; this.remaining = null; this.save(); },
        pPause() { this.remaining = this.pLeft; this.endsAt = null; this.save(); },
        pSkip() {
            if (this.phase === 'focus') { this.focusDone += this.phaseLen - this.pLeft; this.cycles++; this.phase = 'break'; this.endsAt = Date.now() + this.breakLen; }
            else { this.phase = 'focus'; this.endsAt = null; }
            this.remaining = null; this.save();
        },
        checkPhase() {
            if (! this.pRunning || Date.now() < this.endsAt) return;
            if (this.phase === 'focus') {
                const endedAt = this.endsAt;
                this.focusDone += this.focusLen; this.cycles++; this.phase = 'break'; this.endsAt = endedAt + this.breakLen;
                this.announce('Waktunya istirahat ☕', `${this.presets[this.preset][1]} menit. Regangkan badan & minum air.`);
                if (Date.now() < this.endsAt) { this.save(); return; }
            }
            this.phase = 'focus'; this.endsAt = null; this.remaining = null;
            this.announce('Istirahat selesai 🍅', 'Siap untuk sesi fokus berikutnya?');
            this.save();
        },
        pFinish() { this.record(this.pFocusMs); this.phase = 'focus'; this.endsAt = null; this.remaining = null; this.focusDone = 0; this.cycles = 0; this.save(); },
        async pReset() { if (await window.primaConfirm('Reset Pomodoro? Hitungan siklus kembali ke awal.')) { this.phase = 'focus'; this.endsAt = null; this.remaining = null; this.focusDone = 0; this.cycles = 0; this.save(); } },
        record(ms) {
            $wire.set('minutes', Math.max(1, Math.round(ms / 60000)));
            document.getElementById('log-form').scrollIntoView({ behavior: 'smooth' });
        },
        unlockAudio() { try { this.audio ??= new (window.AudioContext || window.webkitAudioContext)(); this.audio.resume(); } catch (e) {} },
        announce(title, body) {
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: title } }));
            navigator.vibrate?.([200, 100, 200]);
            try {
                const ctx = this.audio;
                if (ctx) {
                    [0, 0.35].forEach((offset) => {
                        const osc = ctx.createOscillator(); const gain = ctx.createGain();
                        osc.frequency.value = 880; osc.connect(gain); gain.connect(ctx.destination);
                        gain.gain.setValueAtTime(0.2, ctx.currentTime + offset);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + offset + 0.3);
                        osc.start(ctx.currentTime + offset); osc.stop(ctx.currentTime + offset + 0.3);
                    });
                }
            } catch (e) {}
            if (window.Notification?.permission === 'granted') {
                navigator.serviceWorker?.ready.then((reg) => reg.showNotification(title, { body, tag: 'pomodoro', icon: '/icons/icon-192.png' })).catch(() => {});
            }
        },
    }">
        {{-- Pilih mode --}}
        <div class="mx-auto mb-5 grid max-w-xs grid-cols-2 gap-1 rounded-2xl bg-surface-2 p-1" role="tablist">
            <button type="button" role="tab" x-on:click="setMode('stopwatch')" :aria-selected="mode === 'stopwatch'" :disabled="busy && mode !== 'stopwatch'"
                class="rounded-xl py-2 text-sm font-bold transition disabled:opacity-40" :class="mode === 'stopwatch' ? 'bg-surface text-ink shadow-sm' : 'text-muted'">⏱️ Stopwatch</button>
            <button type="button" role="tab" x-on:click="setMode('pomodoro')" :aria-selected="mode === 'pomodoro'" :disabled="busy && mode !== 'pomodoro'"
                class="rounded-xl py-2 text-sm font-bold transition disabled:opacity-40" :class="mode === 'pomodoro' ? 'bg-surface text-ink shadow-sm' : 'text-muted'">🍅 Pomodoro</button>
        </div>

        {{-- Stopwatch --}}
        <div x-show="mode === 'stopwatch'">
            <p class="text-xs font-bold tracking-wider text-muted uppercase" x-text="running ? 'Sedang fokus…' : (accumulated ? 'Dijeda' : 'Stopwatch')">Stopwatch</p>
            <p class="my-4 font-mono text-5xl font-extrabold tabular-nums sm:text-6xl" :class="running && 'text-brand'" x-text="display">00:00:00</p>
            <div class="flex flex-wrap justify-center gap-2">
                <button type="button" class="btn btn-primary min-w-28" x-show="!running" x-on:click="start()">
                    <x-icon name="play" class="size-4" /> <span x-text="accumulated ? 'Lanjut' : 'Mulai'">Mulai</span>
                </button>
                <button type="button" class="btn btn-ghost min-w-28" x-show="running" x-cloak x-on:click="pause()">
                    <x-icon name="pause" class="size-4" /> Jeda
                </button>
                <button type="button" class="btn btn-ghost" x-show="elapsed > 0" x-cloak x-on:click="finish()">
                    <x-icon name="stop" class="size-4" /> Selesai & catat
                </button>
                <button type="button" class="btn btn-ghost px-3 text-muted" x-show="elapsed > 0 && !running" x-cloak x-on:click="reset()" aria-label="Reset timer">
                    <x-icon name="x" class="size-4" />
                </button>
            </div>
        </div>

        {{-- Pomodoro --}}
        <div x-show="mode === 'pomodoro'" x-cloak>
            <div class="relative mx-auto grid size-52 place-items-center">
                <svg class="absolute inset-0 -rotate-90" viewBox="0 0 200 200" aria-hidden="true">
                    <circle cx="100" cy="100" r="88" fill="none" stroke="var(--surface-2)" stroke-width="12" />
                    <circle cx="100" cy="100" r="88" fill="none" stroke-width="12" stroke-linecap="round" stroke-dasharray="552.92"
                        :stroke="phase === 'focus' ? 'var(--brand)' : 'var(--chart-income)'" :stroke-dashoffset="552.92 * (1 - pProgress)" class="transition-[stroke-dashoffset] duration-1000 ease-linear" />
                </svg>
                <div class="relative">
                    <p class="text-xs font-bold tracking-wider uppercase" :class="phase === 'focus' ? 'text-brand' : 'text-income'" x-text="phase === 'focus' ? 'Fokus' : 'Istirahat'">Fokus</p>
                    <p class="font-mono text-5xl font-extrabold tabular-nums" x-text="pDisplay">25:00</p>
                    <p class="mt-1 text-xs font-semibold text-muted"><span x-text="'🍅'.repeat(Math.min(cycles, 8)) || 'Belum ada'"></span> · <span x-text="Math.round(pFocusMs / 60000) + ' mnt fokus'"></span></p>
                </div>
            </div>
            <div class="my-4 flex justify-center gap-1.5">
                <template x-for="key in Object.keys(presets)" :key="key">
                    <button type="button" class="chip px-3 py-1.5 text-sm transition disabled:opacity-40" x-on:click="preset = key; save()" :disabled="pActive"
                        :class="preset === key ? 'bg-brand text-on-brand' : 'bg-surface-2 text-muted hover:text-ink'" x-text="key"></button>
                </template>
            </div>
            <div class="flex flex-wrap justify-center gap-2">
                <button type="button" class="btn btn-primary min-w-28" x-show="!pRunning" x-on:click="pStart()">
                    <x-icon name="play" class="size-4" /> <span x-text="pActive ? 'Lanjut' : 'Mulai'">Mulai</span>
                </button>
                <button type="button" class="btn btn-ghost min-w-28" x-show="pRunning" x-cloak x-on:click="pPause()">
                    <x-icon name="pause" class="size-4" /> Jeda
                </button>
                <button type="button" class="btn btn-ghost" x-show="pActive" x-cloak x-on:click="pSkip()" :aria-label="phase === 'focus' ? 'Lewati ke istirahat' : 'Lewati istirahat'">
                    <x-icon name="chevron-right" class="size-4" /> <span x-text="phase === 'focus' ? 'Istirahat' : 'Lewati'"></span>
                </button>
                <button type="button" class="btn btn-ghost" x-show="pFocusMs >= 60000" x-cloak x-on:click="pFinish()">
                    <x-icon name="stop" class="size-4" /> Selesai & catat
                </button>
                <button type="button" class="btn btn-ghost px-3 text-muted" x-show="pActive && !pRunning" x-cloak x-on:click="pReset()" aria-label="Reset Pomodoro">
                    <x-icon name="x" class="size-4" />
                </button>
            </div>
            <p class="mt-3 text-xs text-muted">Bunyi & getar saat fase berganti. Aktifkan notifikasi di Akun agar tetap muncul saat layar lain terbuka.</p>
        </div>
    </section>

    {{-- Form catat sesi --}}
    {{-- Saat offline, submit dicegat sebelum sampai ke Livewire lalu sesi disimpan di antrean perangkat --}}
    <section id="log-form" class="card scroll-mt-4"
        x-data="{
            pending: window.primaOffline?.count() ?? 0,
            queueOffline() {
                const minutes = parseInt($wire.minutes, 10);
                if (! $wire.goal_id || ! minutes || minutes < 1) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Isi target dan durasi dulu' } }));
                    return;
                }
                window.primaOffline.queue({
                    goal_id: $wire.goal_id,
                    topic_id: $wire.topic_id || null,
                    studied_on: $wire.studied_on,
                    minutes,
                    score: $wire.score === '' || $wire.score === null ? null : $wire.score,
                    notes: $wire.notes || null,
                });
                $wire.minutes = null;
                $wire.score = null;
                $wire.notes = '';
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Offline: sesi disimpan di perangkat dan dikirim otomatis saat online' } }));
            },
        }"
        x-on:offline-queue-changed.window="pending = $event.detail.count"
        x-on:submit.capture="if (! navigator.onLine && ! $wire.editingId) { $event.preventDefault(); $event.stopPropagation(); queueOffline(); }">
        <h2 class="mb-4 font-bold">{{ $editingId ? 'Edit sesi fokus' : 'Catat sesi fokus' }}</h2>

        <p x-show="pending > 0" x-cloak class="mb-3 flex items-center gap-2 rounded-xl bg-surface-2 px-3 py-2 text-xs font-semibold text-muted">
            <x-icon name="wifi-off" class="size-4" /> <span x-text="`${pending} sesi offline menunggu dikirim`"></span>
        </p>

        @if ($this->goals->isEmpty())
            <p class="text-sm text-muted">Buat target dulu sebelum mencatat. <a href="{{ route('goals.index') }}" wire:navigate class="font-semibold text-brand">Tambah target →</a></p>
        @else
            <form wire:submit="save" class="space-y-4">
                <div>
                    <span class="label">Target</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->goals as $goal)
                            <button type="button" wire:key="pick-{{ $goal->id }}" wire:click="$set('goal_id', {{ $goal->id }})"
                                @class([
                                    'chip px-3 py-1.5 text-sm ring-1 transition',
                                    'text-white ring-transparent' => $goal_id === $goal->id,
                                    'bg-surface text-ink ring-line' => $goal_id !== $goal->id,
                                ])
                                @if ($goal_id === $goal->id) style="background-color: {{ $goal->color }}" @endif>
                                {{ $goal->label() }}
                            </button>
                        @endforeach
                    </div>
                    @error('goal_id') <p class="error">{{ $message }}</p> @enderror
                </div>

                @if ($this->topics->isNotEmpty())
                    <div>
                        <label class="label" for="topic_id">Materi (opsional)</label>
                        <select id="topic_id" wire:model="topic_id" class="input">
                            <option value="">— Fokus umum —</option>
                            @foreach ($this->topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->is_done ? '✓ ' : '' }}{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="minutes">Durasi (menit)</label>
                        <input id="minutes" type="number" inputmode="numeric" min="1" wire:model="minutes" class="input" placeholder="mis. 45">
                        @error('minutes') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="studied_on">Tanggal</label>
                        <input id="studied_on" type="date" wire:model="studied_on" class="input" max="{{ today()->toDateString() }}">
                        @error('studied_on') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ([25, 45, 60, 90, 120] as $preset)
                        <button type="button" class="chip bg-surface-2 px-3 py-1 text-muted hover:text-ink" wire:click="$set('minutes', {{ $preset }})">{{ StudySession::formatMinutes($preset) }}</button>
                    @endforeach
                </div>

                <div>
                    <label class="label" for="score">Skor latihan / try out (opsional)</label>
                    <input id="score" type="number" inputmode="decimal" step="any" min="0" wire:model="score" class="input" placeholder="mis. 520 atau 85">
                    @error('score') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label" for="notes">Catatan</label>
                    <textarea id="notes" wire:model="notes" rows="2" class="input" placeholder="Apa yang dipelajari, kesulitan, link soal…"></textarea>
                </div>

                <div class="flex gap-2">
                    @if ($editingId)
                        <button type="button" class="btn btn-ghost" wire:click="cancelEdit">Batal</button>
                    @endif
                    <button class="btn btn-primary flex-1" wire:loading.attr="disabled" wire:target="save">Simpan sesi</button>
                </div>
            </form>
        @endif
    </section>

    {{-- Riwayat --}}
    <section>
        <h2 class="mb-2 font-bold">Riwayat</h2>
        <div class="card divide-y divide-line p-0">
            @forelse ($this->history as $session)
                <div wire:key="session-{{ $session->id }}" class="flex items-center gap-3 px-4 py-3">
                    <div class="h-9 w-1.5 shrink-0 rounded-full" style="background-color: {{ $session->goal->color }}"></div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">{{ $session->goal->label() }}{{ $session->topic ? ' · '.$session->topic->name : '' }}</p>
                        <p class="truncate text-xs text-muted">
                            {{ $session->studied_on->translatedFormat('D, d M Y') }}
                            @if (! is_null($session->score)) · Skor {{ StudySession::formatScore($session->score) }} @endif
                            {{ $session->notes ? ' · '.$session->notes : '' }}
                        </p>
                    </div>
                    <span class="text-sm font-bold">{{ StudySession::formatMinutes($session->minutes) }}</span>
                    <div class="flex text-muted">
                        <button class="rounded-lg p-1.5 hover:bg-surface-2" wire:click="edit({{ $session->id }})" aria-label="Edit"><x-icon name="pencil" class="size-4" /></button>
                        <button class="rounded-lg p-1.5 hover:bg-danger/10 hover:text-danger" wire:click="delete({{ $session->id }})" wire:confirm="Hapus sesi ini?" aria-label="Hapus"><x-icon name="trash" class="size-4" /></button>
                    </div>
                </div>
            @empty
                <p class="px-4 py-3 text-sm text-muted">Belum ada sesi. Sesi pertama selalu yang paling berat — gas! 🚀</p>
            @endforelse
        </div>
        <div class="mt-3">{{ $this->history->links(data: ['scrollTo' => false]) }}</div>
    </section>
</div>
