<?php

namespace App\Services;

use Exception;

class CsvRowValidator
{
    private array $subjectMap;

    public function __construct(array $subjectMap = [])
    {
        $this->subjectMap = $subjectMap;
    }

    public function setSubjectMap(array $map): void
    {
        $this->subjectMap = $map;
    }

    /**
     * Validate CSV row and prepare data for DB
     * @throws Exception
     */
    public function validateRow(array $row, array $header, int $lineNumber): array
    {
        // Prevent array_combine error if row length mismatch
        if (count($header) !== count($row)) {
            throw new Exception("Column count mismatch at line {$lineNumber}");
        }

        $data = array_combine($header, $row);

        if (empty($data['sbd'])) {
            throw new Exception("Missing SBD");
        }

        // Prepare student data
        $student = [
            'sbd' => trim($data['sbd']),
            'ma_ngoai_ngu' => isset($data['ma_ngoai_ngu']) ? trim($data['ma_ngoai_ngu']) : null,
            'group_a_score' => null,
        ];

        // Calculate group A score (Toán + Lý + Hóa)
        $toan = $this->parseScore($data['toan'] ?? null);
        $ly = $this->parseScore($data['vat_li'] ?? null);
        $hoa = $this->parseScore($data['hoa_hoc'] ?? null);

        if ($toan !== null && $ly !== null && $hoa !== null) {
            $student['group_a_score'] = $toan + $ly + $hoa;
        }

        // Prepare scores
        $scores = [];
        $subjectKeys = ['toan', 'ngu_van', 'ngoai_ngu', 'vat_li', 'hoa_hoc', 'sinh_hoc', 'lich_su', 'dia_li', 'gdcd'];

        foreach ($subjectKeys as $subjectKey) {
            if (isset($data[$subjectKey]) && isset($this->subjectMap[$subjectKey])) {
                $score = $this->parseScore($data[$subjectKey]);
                if ($score !== null) {
                    $scores[] = [
                        'sbd' => $student['sbd'],
                        'subject_key' => $subjectKey,
                        'subject_id' => $this->subjectMap[$subjectKey],
                        'score' => $score,
                    ];
                }
            }
        }

        return ['student' => $student, 'scores' => $scores];
    }

    /**
     * Parse score string to float or null
     * Handles "NA", empty string, and validation range 0-10
     */
    public function parseScore($value): ?float
    {
        if ($value === null || trim($value) === '' || strtoupper(trim($value)) === 'NA') {
            return null;
        }

        if (!is_numeric($value)) {
            return null; // Treat non-numeric values as missing score
        }

        $score = (float) $value;

        if ($score < 0 || $score > 10) {
            throw new Exception("Score out of range: {$value}");
        }

        return $score;
    }
}
