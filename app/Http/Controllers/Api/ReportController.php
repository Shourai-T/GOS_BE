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
            // Optimized Query: Calculate distribution in ONE pass
            $stats = DB::table('scores')
                ->join('subjects', 'scores.subject_id', '=', 'subjects.id')
                ->select(
                    'subjects.key as subject_key',
                    'subjects.name as subject_name',
                    DB::raw('COUNT(CASE WHEN score >= 8 THEN 1 END) as excellent'),
                    DB::raw('COUNT(CASE WHEN score >= 6 AND score < 8 THEN 1 END) as good'),
                    DB::raw('COUNT(CASE WHEN score >= 4 AND score < 6 THEN 1 END) as average'),
                    DB::raw('COUNT(CASE WHEN score < 4 THEN 1 END) as weak')
                )
                ->groupBy('subjects.id', 'subjects.key', 'subjects.name')
                ->get();

            // Transform raw stats into formatted report
            $report = [];
            foreach ($stats as $stat) {
                $report[$stat->subject_key] = [
                    'subject_name' => $stat->subject_name,
                    'excellent' => (int) $stat->excellent,
                    'good' => (int) $stat->good,
                    'average' => (int) $stat->average,
                    'weak' => (int) $stat->weak,
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
        $data = Cache::remember('top_group_a', 3600, function () {
            $topStudents = Student::with('scores.subject')
                ->whereNotNull('group_a_score')
                ->orderBy('group_a_score', 'desc')
                ->limit(10)
                ->get();

            return $topStudents->map(function ($student, $index) {
                return [
                    'rank' => $index + 1,
                    'sbd' => $student->sbd,
                    'group_a_score' => $student->group_a_score,
                    'scores' => $student->scores->mapWithKeys(function ($score) {
                        return [$score->subject->key => $score->score];
                    }),
                ];
            });
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
