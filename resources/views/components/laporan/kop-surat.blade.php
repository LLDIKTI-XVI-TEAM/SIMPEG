@props(['forPdf' => false])

@php
    $imagePath = public_path('img/dikti16-favicon-blue-150x150.png');
    $imageData = file_exists($imagePath) ? base64_encode(file_get_contents($imagePath)) : '';
    // Gunakan base64 untuk DOMPDF agar terhindar dari isu resolusi local file,
    // sebaliknya gunakan URL standar untuk cetak browser agar cache bekerja.
    $imageSrc = $forPdf ? 'data:image/png;base64,'.$imageData : asset('img/dikti16-favicon-blue-150x150.png');
@endphp

<table class="kop-surat" style="width: 100%; border-bottom: 2px solid #000; margin-bottom: 16px; padding-bottom: 10px; border-collapse: collapse; border: none;">
    <tr>
        <td style="width: 80px; text-align: center; vertical-align: middle; padding: 0; border: none;">
            <img src="{{ $imageSrc }}" style="width: 70px; height: 70px; display: block; margin: 0 auto;" alt="Logo LLDIKTI XVI">
        </td>
        <td style="text-align: center; vertical-align: middle; padding: 0; border: none;">
            <div style="font-size: 15px; font-weight: bold; margin: 0 0 2px 0; text-transform: uppercase; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111827;">Kementerian Pendidikan Tinggi, Sains, dan Teknologi</div>
            <div style="font-size: 14px; font-weight: bold; margin: 0 0 4px 0; color: #1d4ed8; text-transform: uppercase; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;">Lembaga Layanan Pendidikan Tinggi (LLDIKTI) Wilayah XVI</div>
            <div style="font-size: 11px; margin: 0; color: #4b5563; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;">Jl. Prof. Dr. Aloei Saboe, Wongkaditi, Kota Gorontalo</div>
        </td>
        <td style="width: 80px; padding: 0; border: none;"></td>
    </tr>
</table>
