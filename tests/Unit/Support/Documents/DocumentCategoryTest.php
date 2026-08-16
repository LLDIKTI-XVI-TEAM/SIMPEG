<?php

namespace Tests\Unit\Support\Documents;

use App\Support\Documents\DocumentCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentCategoryTest extends TestCase
{
    public function test_append_only_sk_keys_are_pangkat_jabatan_and_kgb(): void
    {
        $this->assertSame(
            ['sk_pangkat', 'sk_jabatan', 'sk_kgb'],
            DocumentCategory::appendOnlySkKeys(),
        );
    }

    public function test_replace_sk_is_pengangkatan_only(): void
    {
        $this->assertSame(['sk_pengangkatan'], DocumentCategory::replaceSkKeys());
    }

    public function test_tab_sk_keys_combine_append_and_replace(): void
    {
        $this->assertSame(
            ['sk_pangkat', 'sk_jabatan', 'sk_kgb', 'sk_pengangkatan'],
            DocumentCategory::tabSkKeys(),
        );
    }

    public function test_other_upload_keys_are_non_sk_berkas(): void
    {
        $this->assertSame(
            ['ijazah', 'ktp_kk', 'lainnya'],
            DocumentCategory::otherUploadKeys(),
        );
    }

    #[DataProvider('deletableCategories')]
    public function test_only_berkas_lainnya_are_deletable(string $category, bool $expected): void
    {
        $this->assertSame($expected, DocumentCategory::isDeletable($category));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function deletableCategories(): array
    {
        return [
            'ijazah' => ['ijazah', true],
            'ktp_kk' => ['ktp_kk', true],
            'lainnya' => ['lainnya', true],
            'sk_pangkat' => ['sk_pangkat', false],
            'sk_jabatan' => ['sk_jabatan', false],
            'sk_kgb' => ['sk_kgb', false],
            'sk_pengangkatan' => ['sk_pengangkatan', false],
            'sk_mutasi' => ['sk_mutasi', false],
            'sk_pensiun' => ['sk_pensiun', false],
            'sk_status_pegawai' => ['sk_status_pegawai', false],
            'sk_hukuman_disiplin' => ['sk_hukuman_disiplin', false],
        ];
    }

    public function test_protected_sk_covers_history_and_status_documents(): void
    {
        foreach (DocumentCategory::tabSkKeys() as $key) {
            $this->assertTrue(DocumentCategory::isProtectedSk($key));
        }

        $this->assertTrue(DocumentCategory::isProtectedSk('sk_hukuman_disiplin'));
        $this->assertTrue(DocumentCategory::isProtectedSk('sk_mutasi'));
        $this->assertTrue(DocumentCategory::isProtectedSk('sk_pensiun'));
        $this->assertTrue(DocumentCategory::isProtectedSk('sk_status_pegawai'));
        $this->assertFalse(DocumentCategory::isProtectedSk('lainnya'));
        $this->assertFalse(DocumentCategory::isProtectedSk('ktp_kk'));
    }
}
