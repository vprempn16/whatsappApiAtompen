<?php

namespace App\Traits;

trait ResultTrait
{
    protected function success($data = null, $message = 'Success', $status = 200)
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data
        ], $status);
    }

    protected function error($message = 'Error', $status = 200)
    {
        return response()->json([
            'status' => false,
            'message' => $message
        ], $status);
    }
}
