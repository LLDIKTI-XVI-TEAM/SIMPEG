<x-layouts.app title="SK Wajib per Jenis Pegawai">
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-bold text-ink font-sans">SK Wajib per Jenis Pegawai</h1>
                <p class="mt-1 text-sm text-muted font-sans">
                    Tentukan SK mana yang wajib dimiliki setiap jenis pegawai. Status kelengkapan dokumen pada daftar
                    &amp; detail pegawai mengikuti matriks ini.
                </p>
            </div>
        </div>

        @if(session('success'))
            <div class="rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm font-semibold text-success font-sans">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-lg border border-danger/30 bg-danger/10 px-4 py-3 text-sm font-semibold text-danger font-sans">
                <ul class="list-disc pl-4">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('sk-requirements.update') }}" class="space-y-4">
            @csrf

            <div class="overflow-x-auto rounded-lg border border-border bg-surface shadow-sm">
                <table class="w-full text-left text-sm font-sans">
                    <thead>
                        <tr class="border-b border-border bg-soft/60">
                            <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-muted">Jenis Pegawai</th>
                            @foreach($skPool as $key => $label)
                                <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wider text-muted">
                                    {{ $label }}
                                </th>
                            @endforeach
                            <th class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-muted">Default</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($jenisList as $jenis)
                            <tr class="transition-colors hover:bg-soft/30">
                                <td class="px-4 py-3 font-semibold text-ink">
                                    {{ $jenis->nama }}
                                </td>
                                @foreach($skPool as $key => $label)
                                    <td class="px-4 py-3 text-center">
                                        <input
                                            type="checkbox"
                                            name="matrix[{{ $jenis->id }}][]"
                                            value="{{ $key }}"
                                            @checked(in_array($key, $current[$jenis->id] ?? [], true))
                                            class="h-4 w-4 rounded border-border text-primary focus:ring-primary/30"
                                        >
                                    </td>
                                @endforeach
                                <td class="px-4 py-3 text-xs text-muted">
                                    @if(isset($defaults[$jenis->nama]) && count($defaults[$jenis->nama]) > 0)
                                        {{ count($defaults[$jenis->nama]) }} SK
                                    @else
                                        <span class="italic">(default: semua)</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($skPool) + 2 }}" class="px-4 py-6 text-center text-muted">
                                    Belum ada jenis pegawai.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4 space-y-3 shadow-sm">
                <div class="space-y-1">
                    <label class="text-xs font-bold uppercase tracking-wider text-ink font-sans">Alasan Perubahan <span class="font-normal normal-case text-muted">(opsional, dicatat di audit)</span></label>
                    <input
                        type="text"
                        name="reason"
                        placeholder="Contoh: PPPK hanya wajib 2 SK sesuai kebijakan"
                        class="w-full rounded-lg border border-border bg-surface px-4 py-2 text-sm text-ink shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 font-sans"
                    >
                </div>

                <div class="flex items-center justify-end gap-2">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary/90 font-sans"
                    >
                        Simpan Matriks
                    </button>
                </div>
            </div>
        </form>

        <div class="rounded-lg border border-primary/15 bg-primary/5 p-4 text-xs text-muted font-sans">
            <p class="font-semibold text-ink">Catatan:</p>
            <p class="mt-1">
                Centang SK yang wajib dimiliki jenis pegawai terkait. Jenis pegawai yang belum dikonfigurasi (tidak ada
                baris di matriks) otomatis dianggap wajib semua SK, sehingga perilaku default aman. Default bawaan:
                <strong>PNS &amp; CPNS = 4 SK</strong> (Pengangkatan, Pangkat, Jabatan, KGB); <strong>PPPK = 2 SK</strong>
                (Pengangkatan, KGB).
            </p>
        </div>
    </div>
</x-layouts.app>
