<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Support\PublicStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        PublicStation::resolve($request);

        // Newest 50, then reversed for display. Ordering ascending and taking 50
        // would pin the seed to the first conversation ever once the table grows.
        $messages = ChatMessage::latest('id')
            ->take(50)
            ->get(['id', 'name', 'message', 'created_at'])
            ->reverse()
            ->values();

        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string', 'max:200'],
        ]);

        $stationId = PublicStation::resolve($request)->id;

        $msg = ChatMessage::create($data);

        Cache::put("sse.chat_version.{$stationId}", (string) microtime(true), 3600);

        return response()->json($msg, 201);
    }
}
