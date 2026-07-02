@extends('emails.layout', ['title' => $title])

@section('content')
    <h2 style="margin:0 0 12px;font-size:22px;line-height:1.35;color:#172033;">{{ $title }}</h2>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#334155;">{{ $body }}</p>

    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0;">
        <tr>
            <td style="border-radius:10px;background:#122E92;">
                <a href="{{ $ctaUrl }}" style="display:inline-block;padding:12px 18px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">
                    {{ $ctaLabel }}
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 0;font-size:12px;line-height:1.6;color:#64748b;">
        Jika tombol tidak dapat dibuka, masuk ke SIMPEG melalui browser dan periksa menu notifikasi.
    </p>
@endsection
