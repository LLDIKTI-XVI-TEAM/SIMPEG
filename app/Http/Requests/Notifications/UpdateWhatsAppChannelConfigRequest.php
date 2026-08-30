<?php

namespace App\Http\Requests\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\Notifications\WhatsApp\QontakWhatsAppProviderContract;
use App\Services\Notifications\WhatsApp\WhatsAppConfigSensitiveData;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Konfigurasi Qontak dimuat dari setting aplikasi. Access token dan Channel
 * Integration ID diterima write-only, sedangkan refresh token tidak digunakan.
 */
class UpdateWhatsAppChannelConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        $channel = $this->route('notificationChannel');

        return $this->user()?->role === 'super_admin'
            && $channel instanceof RefNotificationChannel
            && $channel->code === WhatsAppRuntimeConfig::CHANNEL_CODE;
    }

    public function rules(): array
    {
        return [
            'base_url' => ['nullable', 'string', 'max:255', Rule::in([QontakWhatsAppProviderContract::BASE_URL])],
            'canonical_url' => ['nullable', 'string', 'max:255', 'url:https', 'regex:~^https://[^/?#@\s]+/?$~i'],
            'template_configuration' => ['nullable', 'string', 'max:50000'],
            'access_token' => ['nullable', 'string', 'max:10000'],
            'clear_access_token' => ['nullable', 'boolean'],
            'channel_integration_id' => ['nullable', 'string', 'uuid', 'max:36'],
            'clear_channel_integration_id' => ['nullable', 'boolean'],
            'refresh_token' => ['prohibited'],
        ];
    }

    /**
     * Menolak credential pada struktur bersarang sebelum Action memproses config.
     * Credential di luar field write-only resmi tidak boleh menyusup lewat metadata.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('access_token') && $this->boolean('clear_access_token')) {
                $validator->errors()->add(
                    'access_token',
                    'Token baru dan penghapusan token tidak dapat dipilih bersamaan.',
                );
            }

            if ($this->filled('channel_integration_id') && $this->boolean('clear_channel_integration_id')) {
                $validator->errors()->add(
                    'channel_integration_id',
                    'Channel Integration ID baru dan penghapusannya tidak dapat dipilih bersamaan.',
                );
            }

            if (WhatsAppConfigSensitiveData::containsNested($this->all())) {
                $validator->errors()->add(
                    'configuration',
                    'Credential provider tidak boleh dikirim melalui konfigurasi WhatsApp.',
                );
            }

            $canonicalUrl = $this->input('canonical_url');
            if (is_string($canonicalUrl) && trim($canonicalUrl) !== '') {
                $canonicalHost = parse_url(trim($canonicalUrl), PHP_URL_HOST);
                $normalizedHost = is_string($canonicalHost)
                    ? trim(strtolower($canonicalHost), '[]')
                    : '';

                // Tautan template harus memakai origin publik, bukan host lokal worker.
                if (in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true)) {
                    $validator->errors()->add(
                        'canonical_url',
                        'Canonical URL tidak boleh menggunakan host loopback atau localhost.',
                    );
                }
            }

            $contract = $this->input('template_configuration');
            if (is_string($contract) && trim($contract) !== '') {
                $decoded = WhatsAppTemplateConfiguration::decode(
                    $contract,
                    array_keys(WhatsAppTemplateContract::eventTemplateArchetypes()),
                );

                if (! $decoded['valid']) {
                    $validator->errors()->add(
                        'template_configuration',
                        'Kontrak template WhatsApp tidak valid. Wajib berupa JSON berisi event_templates dan templates.',
                    );

                    return;
                }

                if (WhatsAppConfigSensitiveData::templateConfigurationRequiresQuarantine($contract)) {
                    $validator->errors()->add(
                        'template_configuration',
                        'Kontrak template WhatsApp tidak boleh memuat credential provider.',
                    );
                }
            }
        });
    }

    /**
     * Menghapus credential dari request sebelum Laravel mem-flash old input ke session
     * saat validasi gagal. Ini mencegah plaintext tersimpan di session database.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $sanitized = WhatsAppConfigSensitiveData::scrubConfiguration($this->all());
        if ($validator->errors()->has('template_configuration')) {
            // Kontrak invalid tidak boleh di-flash utuh: field tak dikenal dapat memuat
            // credential yang belum tercakup blacklist nama key.
            unset($sanitized['template_configuration']);
        }

        $this->replace($sanitized);

        // FormRequest merupakan salinan request HTTP. Handler exception mem-flash
        // request asal, jadi keduanya harus disanitasi sebelum exception dilempar.
        $baseRequest = $this->container->make('request');
        if ($baseRequest instanceof HttpRequest) {
            $baseRequest->replace($sanitized);
        }

        parent::failedValidation($validator);
    }

    /**
     * @return array<string, string|null>
     */
    public function messages(): array
    {
        return [
            'base_url.in' => 'Base URL harus memakai endpoint Qontak resmi yang ditetapkan.',
            'canonical_url.url' => 'Canonical URL wajib berupa URL HTTPS yang valid.',
            'canonical_url.regex' => 'Canonical URL wajib berupa origin HTTPS tanpa path, query, fragment, atau userinfo.',
            'channel_integration_id.uuid' => 'Channel Integration ID wajib berupa UUID yang valid.',
        ];
    }
}
