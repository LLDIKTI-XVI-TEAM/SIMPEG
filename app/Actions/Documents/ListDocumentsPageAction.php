<?php

namespace App\Actions\Documents;

use App\Support\Documents\DocumentCategory;

class ListDocumentsPageAction
{
    public function execute(): array
    {
        return [
            'categoryLabels' => DocumentCategory::labels(),
        ];
    }
}
