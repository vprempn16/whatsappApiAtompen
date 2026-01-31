<?php

namespace App\Modules\Api\V1\WhatsApp\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SecurityController extends Controller
{

	    public function rotateToken()
    {
        return response()->json([
            'token_rotated' => true
        ]);
    }
}
