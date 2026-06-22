<x-layouts.app title="Edit Pegawai">
    <div class="mx-auto max-w-3xl space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5">
            <h2 class="text-2xl font-bold text-ink font-sans">Edit Data Pegawai</h2>
            <nav class="flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
                <span>/</span>
                <span class="font-medium text-ink">Edit - {{ $p['nama'] }}</span>
            </nav>
        </div>

        {{-- Form Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
            <form action="{{ route('pegawai.update', $p['id']) }}" method="POST" class="space-y-6">
                @csrf

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    
                    {{-- Nama Lengkap --}}
                    <div class="space-y-1">
                        <label for="nama" class="text-sm font-semibold text-ink font-sans">Nama Lengkap <span class="text-danger">*</span></label>
                        <input id="nama" name="nama" type="text" required value="{{ $p['nama'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- NIP --}}
                    <div class="space-y-1">
                        <label for="nip" class="text-sm font-semibold text-ink font-sans">NIP <span class="text-danger">*</span></label>
                        <input id="nip" name="nip" type="text" required value="{{ $p['nip'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Jabatan --}}
                    <div class="space-y-1">
                        <label for="jabatan" class="text-sm font-semibold text-ink font-sans">Jabatan <span class="text-danger">*</span></label>
                        <input id="jabatan" name="jabatan" type="text" required value="{{ $p['jabatan'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Unit Kerja --}}
                    <div class="space-y-1">
                        <label for="unit" class="text-sm font-semibold text-ink font-sans">Unit Kerja <span class="text-danger">*</span></label>
                        <select id="unit" name="unit" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="Bag. Umum" {{ $p['unit'] === 'Bag. Umum' ? 'selected' : '' }}>Bag. Umum</option>
                            <option value="Bag. Keuangan" {{ $p['unit'] === 'Bag. Keuangan' ? 'selected' : '' }}>Bag. Keuangan</option>
                            <option value="Bag. SDM" {{ $p['unit'] === 'Bag. SDM' ? 'selected' : '' }}>Bag. SDM</option>
                            <option value="Bag. IT" {{ $p['unit'] === 'Bag. IT' ? 'selected' : '' }}>Bag. IT</option>
                        </select>
                    </div>

                    {{-- Golongan --}}
                    <div class="space-y-1">
                        <label for="golongan" class="text-sm font-semibold text-ink font-sans">Golongan <span class="text-danger">*</span></label>
                        <select id="golongan" name="golongan" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            @foreach(['I/a','I/b','I/c','I/d','II/a','II/b','II/c','II/d','III/a','III/b','III/c','III/d','IV/a','IV/b','IV/c','IV/d','IV/e'] as $gol)
                                <option value="{{ $gol }}" {{ $p['golongan'] === $gol ? 'selected' : '' }}>{{ $gol }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Status Kepegawaian --}}
                    <div class="space-y-1">
                        <label for="jenis" class="text-sm font-semibold text-ink font-sans">Status Kepegawaian <span class="text-danger">*</span></label>
                        <select id="jenis" name="jenis" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="PNS" {{ $p['jenis'] === 'PNS' ? 'selected' : '' }}>PNS</option>
                            <option value="PPPK" {{ $p['jenis'] === 'PPPK' ? 'selected' : '' }}>PPPK</option>
                        </select>
                    </div>

                    {{-- TMT --}}
                    <div class="space-y-1">
                        <label for="tmt" class="text-sm font-semibold text-ink font-sans">TMT <span class="text-danger">*</span></label>
                        @php
                            // format date to Y-m-d for date input
                            $formattedTmt = '';
                            try {
                                $formattedTmt = \Carbon\Carbon::parse($p['tmt'])->format('Y-m-d');
                            } catch (\Exception $e) {
                                $formattedTmt = $p['tmt'];
                            }
                        @endphp
                        <input id="tmt" name="tmt" type="date" value="{{ $formattedTmt }}" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Email --}}
                    <div class="space-y-1">
                        <label for="email" class="text-sm font-semibold text-ink font-sans">Email</label>
                        <input id="email" name="email" type="email" value="{{ $p['email'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Telepon --}}
                    <div class="space-y-1 sm:col-span-2">
                        <label for="telepon" class="text-sm font-semibold text-ink font-sans">Nomor Telepon</label>
                        <input id="telepon" name="telepon" type="tel" value="{{ $p['telepon'] ?? '' }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-6 flex justify-end gap-3">
                    <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft">
                        Batal
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
