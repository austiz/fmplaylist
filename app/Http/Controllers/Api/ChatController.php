<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChatController extends Controller
{
    public function index(): JsonResponse
    {
        $messages = ChatMessage::orderBy('created_at')->take(50)->get(['id', 'name', 'message', 'created_at']);
        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:30'],
            'message' => ['required', 'string', 'max:200'],
        ]);

        $msg = ChatMessage::create($data);

        Cache::put('sse.chat_version', (string) microtime(true), 3600);

        return response()->json($msg, 201);
    }
}
