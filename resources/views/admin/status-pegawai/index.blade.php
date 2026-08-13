<x-layouts.app title="Status Pegawai">

    <x-admin.page-header title="Status Pegawai" class="mb-6">
        <x-slot:breadcrumb>
            <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
            <span>/</span>
            <span class="font-medium text-ink">Status Pegawai</span>
            <span>•</span>
            <span class="text-muted italic">Akses: Khusus Super Admin</span>
        </x-slot:breadcrumb>
    </x-admin.page-header>

    <div class="space-y-6">
        <div class="rounded-xl border border-border bg-surface p-6 shadow-sm">
            <h2 class="mb-4 text-lg font-semibold text-ink">Form Ubah Status Pegawai</h2>

            @if(session('success'))
                <div class="mb-6 rounded-lg bg-success/10 p-4 border border-success/20">
                    <p class="text-sm font-medium text-success">{{ session('success') }}</p>
                </div>
            @endif

            @if(session('error'))
                <div class="mb-6 rounded-lg bg-error/10 p-4 border border-error/20">
                    <p class="text-sm font-medium text-error">{{ session('error') }}</p>
                </div>
            @endif

            <p class="mb-6 text-sm text-muted">
                Semua pengaturan status kepegawaian dikelola dari halaman ini. Perubahan akan otomatis tercatat sebagai riwayat pada profil pegawai
                dan pegawai bersangkutan akan menerima notifikasi. Berkas SK hanya akan tersimpan ke arsip dokumen pegawai bila Anda melampirkannya.
            </p>

            <form action="{{ route('super-admin.status-pegawai.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div class="grid gap-5 md:grid-cols-2">
                    {{-- Pegawai --}}
                    <div>
                        <label for="pegawai_id" class="mb-1 block text-sm font-semibold text-ink">Pegawai <span class="text-error">*</span></label>
                        <select name="pegawai_id" id="pegawai_id" class="mt-1 block w-full rounded-lg border-border bg-page py-2 pl-3 pr-10 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary @error('pegawai_id') border-error @enderror">
                            <option value="">-- Pilih Pegawai --</option>
                            @foreach($employees as $employee)
                                <option value="{{ $employee->id }}" {{ old('pegawai_id') == $employee->id ? 'selected' : '' }}>
                                    {{ $employee->nama_lengkap }} - {{ $employee->nip }}
                                </option>
                            @endforeach
                        </select>
                        @error('pegawai_id') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Status Baru --}}
                    <div>
                        <label for="status_pegawai_id" class="mb-1 block text-sm font-semibold text-ink">Status Baru <span class="text-error">*</span></label>
                        <select name="status_pegawai_id" id="status_pegawai_id" class="mt-1 block w-full rounded-lg border-border bg-page py-2 pl-3 pr-10 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary @error('status_pegawai_id') border-error @enderror">
                            <option value="">-- Pilih Status --</option>
                            @foreach($statusOptions as $status)
                                <option value="{{ $status->id }}" {{ old('status_pegawai_id') == $status->id ? 'selected' : '' }}>
                                    {{ $status->nama }}
                                </option>
                            @endforeach
                        </select>
                        @error('status_pegawai_id') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Tanggal --}}
                    <div>
                        <label for="tanggal" class="mb-1 block text-sm font-semibold text-ink">Tanggal Efektif <span class="text-error">*</span></label>
                        <input type="date" name="tanggal" id="tanggal" value="{{ old('tanggal') }}" class="mt-1 block w-full rounded-lg border-border bg-page px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary @error('tanggal') border-error @enderror">
                        @error('tanggal') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                    </div>

                    {{-- Nomor Berkas (Otomatis) --}}
                    <div>
                        <label for="nomor_berkas" class="mb-1 block text-sm font-semibold text-ink">Nomor Berkas (Otomatis)</label>
                        <input type="text" name="nomor_berkas" id="nomor_berkas" value="SK-STATUS-{{ date('Ymd') }}-XXX" disabled class="mt-1 block w-full rounded-lg border-border bg-page/50 px-3 py-2 text-sm text-muted shadow-sm cursor-not-allowed">
                        <p class="mt-1 text-xs text-muted">Nomor berkas hanya dibuat otomatis apabila Anda melampirkan berkas pendukung.</p>
                    </div>
                </div>

                {{-- Keterangan --}}
                <div>
                    <label for="keterangan" class="mb-1 block text-sm font-semibold text-ink">Keterangan <span class="text-muted">(Opsional)</span></label>
                    <textarea name="keterangan" id="keterangan" rows="3" placeholder="Masukkan catatan atau keterangan perubahan status..." class="mt-1 block w-full rounded-lg border-border bg-page px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary @error('keterangan') border-error @enderror">{{ old('keterangan') }}</textarea>
                    @error('keterangan') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                </div>

                {{-- Upload Berkas --}}
                <div>
                    <label for="berkas" class="mb-1 block text-sm font-semibold text-ink">Upload Berkas SK Pendukung <span class="text-muted">(Opsional)</span></label>
                    <input type="file" name="berkas" id="berkas" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded-lg file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-dark @error('berkas') border-error @enderror">
                    <p class="mt-1 text-xs text-muted">Format file yang diizinkan: PDF, JPG, PNG (Maksimal 10MB). Bila diisi, berkas akan tersimpan ke arsip Dokumen SK pegawai.</p>
                    @error('berkas') <span class="text-xs text-error mt-1">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <x-ui.button type="reset" variant="muted">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Simpan Status</x-ui.button>
                </div>
            </form>
        </div>


</x-layouts.app>
