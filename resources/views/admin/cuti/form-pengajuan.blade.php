<x-layouts.app title="Ajukan Cuti">
    <div class="space-y-6">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Form Pengajuan Cuti</h2>
                <nav class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('cuti') }}" class="transition-colors hover:text-ink">Cuti</a>
                    <span>/</span>
                    <span class="font-medium text-ink">Ajukan Cuti</span>
                </nav>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <form action="{{ route('cuti.store') }}" method="POST" enctype="multipart/form-data" class="rounded-xl border border-border bg-surface shadow-sm overflow-hidden" x-data="cutiForm()">
                    @csrf
                    
                    <div class="border-b border-border bg-soft px-6 py-4">
                        <h3 class="text-lg font-semibold text-ink">Detail Cuti</h3>
                        <p class="text-sm text-muted">Lengkapi form di bawah ini untuk mengajukan cuti.</p>
                    </div>

                    <div class="p-6 space-y-5">
                        <!-- Alert jika belum ada atasan langsung -->
                        @if(!$employee || !$employee->kepala_bagian_id)
                        <div class="rounded-lg bg-warning/10 p-4 border-l-4 border-warning">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-warning-dark font-medium text-ink">
                                        Anda belum memiliki Kepala Bagian yang dikonfigurasi. Pengajuan cuti mungkin tidak dapat diproses.
                                    </p>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Jenis Cuti -->
                        <div>
                            <label for="jenis_cuti_id" class="block text-sm font-medium text-ink mb-1">Jenis Cuti <span class="text-danger">*</span></label>
                            <x-form.select id="jenis_cuti_id" name="jenis_cuti_id" required x-model="selectedJenisCuti" @change="validateSaldo"
                               
                                {{ (!$employee || !$employee->kepala_bagian_id) ? 'disabled' : '' }}>
                                <option value="">Pilih Jenis Cuti</option>
                                @foreach($jenisCuti as $jenis)
                                    <option value="{{ $jenis->id }}" data-nama="{{ $jenis->nama }}">{{ $jenis->nama }}</option>
                                @endforeach
                            </x-form.select>
                            @error('jenis_cuti_id')
                                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <!-- Tanggal Mulai -->
                            <div>
                                <label for="tanggal_mulai" class="block text-sm font-medium text-ink mb-1">Tanggal Mulai <span class="text-danger">*</span></label>
                                <input type="date" id="tanggal_mulai" name="tanggal_mulai" required x-model="startDate" @change="calculateDays"
                                    class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 transition-all"
                                    {{ (!$employee || !$employee->kepala_bagian_id) ? 'disabled' : '' }}>
                                @error('tanggal_mulai')
                                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Tanggal Selesai -->
                            <div>
                                <label for="tanggal_selesai" class="block text-sm font-medium text-ink mb-1">Tanggal Selesai <span class="text-danger">*</span></label>
                                <input type="date" id="tanggal_selesai" name="tanggal_selesai" required x-model="endDate" @change="calculateDays"
                                    class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 transition-all"
                                    {{ (!$employee || !$employee->kepala_bagian_id) ? 'disabled' : '' }}>
                                @error('tanggal_selesai')
                                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Jumlah Hari Kerja (Readonly, calculated via AJAX) -->
                        <div>
                            <label for="jumlah_hari_kerja" class="block text-sm font-medium text-ink mb-1">Jumlah Hari Kerja</label>
                            <div class="relative">
                                <input type="number" id="jumlah_hari_kerja" name="jumlah_hari_kerja" readonly x-model="workDays"
                                    class="w-full rounded-lg border border-border bg-soft px-4 py-2.5 text-sm text-muted cursor-not-allowed transition-all">
                                <div class="absolute inset-y-0 right-0 flex items-center pr-3" x-show="isCalculating">
                                    <svg class="animate-spin h-4 w-4 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-muted" x-show="!isCalculating">Dihitung otomatis (mengabaikan akhir pekan dan libur nasional).</p>
                            <p class="mt-1 text-xs text-danger font-medium" x-show="saldoError" x-text="saldoErrorMsg"></p>
                        </div>

                        <!-- Alasan -->
                        <div>
                            <label for="alasan" class="block text-sm font-medium text-ink mb-1">Alasan Cuti <span class="text-danger">*</span></label>
                            <textarea id="alasan" name="alasan" rows="3" required
                                class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20 transition-all"
                                placeholder="Jelaskan alasan cuti Anda secara singkat..."
                                {{ (!$employee || !$employee->kepala_bagian_id) ? 'disabled' : '' }}></textarea>
                            @error('alasan')
                                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Lampiran -->
                        <div>
                            <label for="file_lampiran" class="block text-sm font-medium text-ink mb-1">File Lampiran <span class="text-muted font-normal">(Opsional)</span></label>
                            <input type="file" id="file_lampiran" name="file_lampiran" accept=".pdf,.jpg,.jpeg,.png"
                                class="w-full text-sm text-muted file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 transition-all border border-border rounded-lg bg-surface"
                                {{ (!$employee || !$employee->kepala_bagian_id) ? 'disabled' : '' }}>
                            <p class="mt-1 text-xs text-muted">Format: PDF, JPG, PNG. Maksimal ukuran file: 10MB.</p>
                            @error('file_lampiran')
                                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="bg-soft border-t border-border px-6 py-4 flex items-center justify-end gap-3">
                        <a href="{{ route('cuti') }}" class="px-5 py-2.5 text-sm font-medium text-muted hover:text-ink transition-colors">Batal</a>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-primary/90 focus:ring-2 focus:ring-primary/20 focus:outline-none disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="saldoError || {{ (!$employee || !$employee->kepala_bagian_id) ? 'true' : 'false' }}">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                            </svg>
                            Kirim Pengajuan
                        </button>
                    </div>
                </form>
            </div>

            <!-- Sidebar Info -->
            <div class="space-y-6">
                <!-- Info Saldo Cuti -->
                <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-ink mb-4 flex items-center">
                        <svg class="w-4 h-4 mr-1.5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Info Saldo Cuti Tahunan
                    </h3>
                    
                    @if($saldoTahunan)
                    <div class="space-y-3">
                        <div class="flex justify-between items-center pb-2 border-b border-border/50">
                            <span class="text-sm text-muted">Jatah Tahun Ini</span>
                            <span class="text-sm font-semibold text-ink">{{ $saldoTahunan->jatah_awal }} Hari</span>
                        </div>
                        <div class="flex justify-between items-center pb-2 border-b border-border/50">
                            <span class="text-sm text-muted">Sisa Tahun Lalu</span>
                            <span class="text-sm font-semibold text-ink">{{ $saldoTahunan->carry_over }} Hari</span>
                        </div>
                        <div class="flex justify-between items-center pb-2 border-b border-border/50">
                            <span class="text-sm text-muted">Sudah Terpakai</span>
                            <span class="text-sm font-semibold text-danger">{{ $saldoTahunan->terpakai }} Hari</span>
                        </div>
                        <div class="flex justify-between items-center pt-1">
                            <span class="text-sm font-semibold text-ink">Sisa Saldo</span>
                            <span class="text-lg font-bold text-success">{{ $saldoTahunan->sisa }} Hari</span>
                        </div>
                    </div>
                    @else
                    <div class="text-center py-4">
                        <p class="text-sm text-muted">Data saldo cuti belum tersedia.</p>
                    </div>
                    @endif
                </div>

                <!-- Info Approval -->
                <div class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-ink mb-4 flex items-center">
                        <svg class="w-4 h-4 mr-1.5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Proses Persetujuan
                    </h3>
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary mr-3 mt-0.5">1</div>
                            <div>
                                <p class="text-sm font-medium text-ink">Kepala Bagian</p>
                                <p class="text-xs text-muted mt-0.5">Pemeriksaan tahap pertama oleh atasan langsung Anda.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary mr-3 mt-0.5">2</div>
                            <div>
                                <p class="text-sm font-medium text-ink">Verifikator / Kabag</p>
                                <p class="text-xs text-muted mt-0.5">Verifikasi lanjutan sesuai struktur unit kerja.</p>
                            </div>
                        </li>
                        <li class="flex items-start">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary mr-3 mt-0.5">3</div>
                            <div>
                                <p class="text-sm font-medium text-ink">Pimpinan</p>
                                <p class="text-xs text-muted mt-0.5">Persetujuan akhir oleh pimpinan.</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- AlpineJS logic for form -->
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('cutiForm', () => ({
                startDate: '',
                endDate: '',
                workDays: 0,
                isCalculating: false,
                selectedJenisCuti: '',
                sisaSaldoTahunan: {{ $saldoTahunan ? $saldoTahunan->sisa : 0 }},
                saldoError: false,
                saldoErrorMsg: '',
                
                validateSaldo() {
                    this.saldoError = false;
                    this.saldoErrorMsg = '';
                    
                    const select = document.getElementById('jenis_cuti_id');
                    if (select.selectedIndex > 0) {
                        const namaJenis = select.options[select.selectedIndex].getAttribute('data-nama');
                        
                        // Jika cuti tahunan dan jumlah hari melebihi sisa saldo
                        if (namaJenis && namaJenis.toLowerCase().includes('tahunan') && this.workDays > this.sisaSaldoTahunan) {
                            this.saldoError = true;
                            this.saldoErrorMsg = `Saldo cuti tahunan tidak mencukupi. Sisa saldo Anda: ${this.sisaSaldoTahunan} hari, sedangkan pengajuan: ${this.workDays} hari.`;
                        }
                    }
                },
                
                async calculateDays() {
                    if (!this.startDate || !this.endDate) {
                        this.workDays = 0;
                        return;
                    }
                    
                    const start = new Date(this.startDate);
                    const end = new Date(this.endDate);
                    
                    if (start > end) {
                        this.workDays = 0;
                        return;
                    }
                    
                    this.isCalculating = true;
                    
                    try {
                        const response = await fetch(`/api/v1/cuti/calculate-workdays?start=${this.startDate}&end=${this.endDate}`);
                        if (response.ok) {
                            const result = await response.json();
                            this.workDays = result.data;
                            this.validateSaldo();
                        } else {
                            console.error('Failed to calculate workdays');
                        }
                    } catch (error) {
                        console.error('Error fetching workdays', error);
                    } finally {
                        this.isCalculating = false;
                    }
                }
            }));
        });
    </script>
</x-layouts.app>
