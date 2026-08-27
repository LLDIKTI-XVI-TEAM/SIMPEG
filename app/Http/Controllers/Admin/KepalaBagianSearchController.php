<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\SearchKepalaBagianWorkspaceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\KepalaBagianWorkspaceSearchRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class KepalaBagianSearchController extends Controller
{
    /** Menyajikan hasil pencarian ringkas setelah Action menegakkan scope bawahan langsung. */
    public function __invoke(
        KepalaBagianWorkspaceSearchRequest $request,
        SearchKepalaBagianWorkspaceAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $query = $request->validated('q');

        return response()->json($action->execute($actor, is_string($query) ? $query : null));
    }
}
