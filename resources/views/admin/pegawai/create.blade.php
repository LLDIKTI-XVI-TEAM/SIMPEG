<x-layouts.app title="Tambah Pegawai">
    <div class="mx-auto max-w-3xl space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Tambah Pegawai Baru</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Tambah</span>
            </nav>
        </div>

        {{-- Form Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
            <form action="{{ route('pegawai.store') }}" method="POST" class="space-y-6">
                @csrf

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    
                    {{-- Nama Lengkap --}}
                    <div class="space-y-1">
                        <label for="nama" class="text-sm font-semibold text-ink font-sans">Nama Lengkap <span class="text-danger">*</span></label>
                        <input id="nama" name="nama" type="text" required placeholder="Contoh: Ahmad Fauzi, S.Kom." class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- NIP --}}
                    <div class="space-y-1">
                        <label for="nip" class="text-sm font-semibold text-ink font-sans">NIP <span class="text-danger">*</span></label>
                        <input id="nip" name="nip" type="text" required placeholder="Contoh: 19850312201001 1 001" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Jabatan --}}
                    <div class="space-y-1">
                        <label for="jabatan" class="text-sm font-semibold text-ink font-sans">Jabatan <span class="text-danger">*</span></label>
                        <input id="jabatan" name="jabatan" type="text" required placeholder="Contoh: Analis Kepegawaian" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Unit Kerja --}}
                    <div class="space-y-1">
                        <label for="unit" class="text-sm font-semibold text-ink font-sans">Unit Kerja <span class="text-danger">*</span></label>
                        <select id="unit" name="unit" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="">Pilih Unit Kerja...</option>
                            <option value="Bag. Umum">Bag. Umum</option>
                            <option value="Bag. Keuangan">Bag. Keuangan</option>
                            <option value="Bag. SDM">Bag. SDM</option>
                            <option value="Bag. IT">Bag. IT</option>
                        </select>
                    </div>

                    {{-- Golongan --}}
                    <div class="space-y-1">
                        <label for="golongan" class="text-sm font-semibold text-ink font-sans">Golongan <span class="text-danger">*</span></label>
                        <select id="golongan" name="golongan" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="">Pilih Golongan...</option>
                            <option value="I/a">I/a</option>
                            <option value="I/b">I/b</option>
                            <option value="I/c">I/c</option>
                            <option value="I/d">I/d</option>
                            <option value="II/a">II/a</option>
                            <option value="II/b">II/b</option>
                            <option value="II/c">II/c</option>
                            <option value="II/d">II/d</option>
                            <option value="III/a">III/a</option>
                            <option value="III/b">III/b</option>
                            <option value="III/c">III/c</option>
                            <option value="III/d">III/d</option>
                            <option value="IV/a">IV/a</option>
                            <option value="IV/b">IV/b</option>
                            <option value="IV/c">IV/c</option>
                            <option value="IV/d">IV/d</option>
                            <option value="IV/e">IV/e</option>
                        </select>
                    </div>

                    {{-- Jenis Kepegawaian --}}
                    <div class="space-y-1">
                        <label for="jenis" class="text-sm font-semibold text-ink font-sans">Status Kepegawaian <span class="text-danger">*</span></label>
                        <select id="jenis" name="jenis" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="">Pilih Status...</option>
                            <option value="PNS">PNS</option>
                            <option value="CPNS">CPNS</option>
                            <option value="PPPK">PPPK</option>
                            <option value="PPNPN">PPNPN</option>
                        </select>
                    </div>

                    {{-- TMT --}}
                    <div class="space-y-1">
                        <label for="tmt" class="text-sm font-semibold text-ink font-sans">TMT <span class="text-danger">*</span></label>
                        <input id="tmt" name="tmt" type="date" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Email --}}
                    <div class="space-y-1">
                        <label for="email" class="text-sm font-semibold text-ink font-sans">Email</label>
                        <input id="email" name="email" type="email" placeholder="Contoh: pegawai@domain.com" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Telepon --}}
                    <div class="space-y-1 sm:col-span-2">
                        <label for="telepon" class="text-sm font-semibold text-ink font-sans">Nomor Telepon</label>
                        <input id="telepon" name="telepon" type="tel" placeholder="Contoh: 081234567890" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-6 flex justify-end gap-3">
                    <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft">
                        Batal
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                        Simpan Pegawai
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
