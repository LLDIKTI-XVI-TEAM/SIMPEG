<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ModalAccessibilitySourceTest extends TestCase
{
    public function test_initial_focus_memprioritaskan_error_lalu_marker_eksplisit_sebelum_fallback_generik(): void
    {
        $path = dirname(__DIR__, 2).'/resources/views/components/ui/modal.blade.php';
        $source = file_get_contents($path);

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            '/const errorTarget = .*data-error-autofocus=true.*;\s*'
            .'const explicitTarget = .*data-modal-initial-focus=true.*;\s*'
            .'const genericTarget = .*;\s*'
            .'const initial = errorTarget \?\? explicitTarget \?\? genericTarget \?\? \$el;/s',
            $source,
        );
    }

    public function test_escape_hanya_menutup_modal_yang_sedang_terbuka(): void
    {
        $modalPath = dirname(__DIR__, 2).'/resources/views/components/ui/modal.blade.php';
        $modalSource = file_get_contents($modalPath);
        $adminDetailPath = dirname(__DIR__, 2).'/resources/views/admin/cuti/show.blade.php';
        $adminDetailSource = file_get_contents($adminDetailPath);

        $this->assertIsString($modalSource);
        $this->assertStringContainsString(
            '@if ($show && $closeAction) @keydown.escape.window="if ({{ $show }}) { {{ $closeAction }} }" @endif',
            $modalSource,
        );
        $this->assertStringNotContainsString(
            '@if ($closeAction) @keydown.escape.window="{{ $closeAction }}" @endif',
            $modalSource,
        );

        $this->assertIsString($adminDetailSource);
        $this->assertStringContainsString(
            '@keydown.escape.window="if (decisionForm !== null) close()"',
            $adminDetailSource,
        );
        $this->assertStringNotContainsString(
            '@keydown.escape.window="close()"',
            $adminDetailSource,
        );
    }
}
