<?php

namespace App\Modules\Api\V1\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CRM\RecordObject;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller{

	public function index()
	{
		return response()->json([
			'conversations' => []
		]);
	}

	public function show($id)
	{
		return response()->json([
			'conversation_id' => $id,
			'messages' => []
		]);
	}

	public function assign(Request $request, $id)
	{
		$request->validate([
			'user_id' => 'required'
		]);

		return response()->json([
			'conversation_id' => $id,
			'assigned_to' => $request->user_id
		]);
	}

	public function updateStatus(Request $request, $id)
	{
		$request->validate([
			'status' => 'required|in:open,pending,closed'
		]);

		return response()->json([
			'conversation_id' => $id,
			'status' => $request->status
		]);
	}

	public function checkWindow($id)
	{
		return response()->json([
			'conversation_id' => $id,
			'within_24h' => true
		]);
	}
}
