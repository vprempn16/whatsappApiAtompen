<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SpeechController extends Controller
{
    public function index()
    {
        return view('transcribe');
    }

    public function transcribe(Request $request)
    {
        $request->validate([
            'audio' => 'required|file|max:30720', // 30 MB max
        ]);

        $audioFile = $request->file('audio');

        try {
            // Send the file to local Flask Whisper service
            $response = Http::attach(
                'audio',
                file_get_contents($audioFile->getRealPath()),
                $audioFile->getClientOriginalName()
            )->post('http://127.0.0.1:5000/transcribe');

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'error' => 'Flask service returned an error.',
                'details' => $response->body()
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to connect to Flask transcription service.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
