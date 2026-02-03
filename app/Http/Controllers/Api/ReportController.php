<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use App\Models\Score;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

use App\Http\Controllers\Controller;

class ReportController extends Controller
{
    /**
     * Get score distribution report (4 levels) for all subjects
     * Cached for performance
     */
    public function getDistribution(): JsonResponse
    {
        $data = Cache::remember('score_distribution', 3600, function () {
            $subjects = Subject::all();
            $report = [];

            foreach ($subjects as $subject) {
                $report[$subject->key] = [
                    'subject_name' => $subject->name,
                    'excellent' => Score::where('subject_id', $subject->id)
                        ->where('score', '>=', 8)
                        ->count(),
                    'good' => Score::where('subject_id', $subject->id)
                        ->where('score', '>=', 6)
                        ->where('score', '<', 8)
                        ->count(),
                    'average' => Score::where('subject_id', $subject->id)
                        ->where('score', '>=', 4)
                        ->where('score', '<', 6)
                        ->count(),
                    'weak' => Score::where('subject_id', $subject->id)
                        ->where('score', '<', 4)
                        ->count(),
                ];
            }

            return $report;
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get Top 10 students in Group A (Math + Physics + Chemistry)
     */
    public function getTopGroupA(): JsonResponse
    {
        $topStudents = Student::with('scores.subject')
            ->whereNotNull('group_a_score')
            ->orderBy('group_a_score', 'desc')
            ->limit(10)
            ->get();

        $data = $topStudents->map(function ($student, $index) {
            return [
                'rank' => $index + 1,
                'sbd' => $student->sbd,
                'group_a_score' => $student->group_a_score,
                'scores' => $student->scores->mapWithKeys(function ($score) {
                    return [$score->subject->key => $score->score];
                }),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
