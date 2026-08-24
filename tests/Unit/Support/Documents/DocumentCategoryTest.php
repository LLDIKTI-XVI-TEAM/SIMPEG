<?php

namespace Tests\Unit\Support\Documents;

use App\Support\Documents\DocumentCategory;
use Tests\TestCase;

class DocumentCategoryTest extends TestCase
{
    public function test_other_upload_keys_are_non_sk_berkas(): void
    {
        $this->assertSame(
            ['ijazah', 'ktp_kk', 'lainnya'],
            DocumentCategory::otherUploadKeys(),
        );
    }

    public function test_protected_sk_covers_history_and_status_documents(): void
    {
        foreach (['sk_pengangkatan', 'sk_pangkat', 'sk_jabatan', 'sk_kgb'] as $key) {
            $this->assertTrue(DocumentCategory::isProtectedSk($key));
        }

        $this->assertTrue(DocumentCategory::isProtectedSk('sk_hukuman_disiplin'));
        $this->assertTrue(DocumentCategory::isProtectedSk('sk_mutasi'));
        $this->assertTrue(DocumentCategory::isProtectedSk('sk_pensiun'));
        $this->assertTrue(DocumentCategory::isProtectedSk('sk_status_pegawai'));
        $this->assertFalse(DocumentCategory::isProtectedSk('lainnya'));
        $this->assertFalse(DocumentCategory::isProtectedSk('ktp_kk'));
    }

    public function test_legacy_sk_remains_protected_without_becoming_an_editable_category(): void
    {
        $this->assertTrue(DocumentCategory::isProtectedSk('SK'));
        $this->assertSame('Dokumen SK', DocumentCategory::label('SK'));
        $this->assertNotContains('SK', DocumentCategory::editableKeys());
    }
}
