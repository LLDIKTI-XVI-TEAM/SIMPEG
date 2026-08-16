{{ $value ? \Illuminate\Support\Carbon::parse($value)->format('d-m-Y') : ($fallback ?? '-') }}
