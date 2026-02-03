<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Http\Controllers\Controller;

class ScoreController extends Controller
{
    /**
     * Search student by SBD and return their scores
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'sbd' => 'required|string|max:20',
        ]);

        $student = Student::with('scores.subject')
            ->where('sbd', $request->sbd)
            ->first();

        if (!$student) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy thí sinh với SBD này',
            ], 404);
        }

        // Format scores for response
        $scores = $student->scores->mapWithKeys(function ($score) {
            return [$score->subject->key => $score->score];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'sbd' => $student->sbd,
                'ma_ngoai_ngu' => $student->ma_ngoai_ngu,
                'group_a_score' => $student->group_a_score,
                'scores' => $scores,
            ],
        ]);
    }
}
