<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Score;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportScoresCommand extends Command
{
    protected $signature = 'app:import-scores {file : Path to CSV file} {--chunk-size=1000 : Number of rows per chunk}';
    protected $description = 'Import student scores from CSV with batch insert and idempotency';

    private $subjectMap = [];
    private ImportJob $job;
    private $errorLogFile;

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $chunkSize = (int) $this->option('chunk-size');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        $fileHash = md5_file($filePath);

        // Check for existing active import
        $existingJob = ImportJob::where('file_hash', $fileHash)
            ->whereIn('status', [ImportJob::STATUS_PENDING, ImportJob::STATUS_PROCESSING])
            ->first();

        if ($existingJob) {
            $this->error("Import already in progress for this file (Job ID: {$existingJob->id})");
            return self::FAILURE;
        }

        // Create import job
        $this->job = ImportJob::create([
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'status' => ImportJob::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        $this->info("Starting import (Job ID: {$this->job->id})");

        try {
            // Initialize error log file
            $this->initErrorLog();

            // Load subject mappings
            $this->loadSubjectMap();

            // Count total rows
            $totalRows = $this->countCsvRows($filePath);
            $this->job->update(['total_rows' => $totalRows]);

            $this->info("Total rows to process: {$totalRows}");

            // Process CSV in chunks
            $this->processChunks($filePath, $chunkSize);

            // Mark as done
            $this->job->update([
                'status' => ImportJob::STATUS_DONE,
                'finished_at' => now(),
            ]);

            $this->info("Import completed successfully!");
            $this->info("Processed: {$this->job->processed_rows}/{$this->job->total_rows}");
            $this->info("Errors: {$this->job->error_rows}");

            if ($this->job->error_rows > 0) {
                $this->warn("Error log: {$this->errorLogFile}");
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->job->update([
                'status' => ImportJob::STATUS_FAILED,
                'finished_at' => now(),
            ]);

            $this->error("Import failed: " . $e->getMessage());
            Log::error("Import failed", ['job_id' => $this->job->id, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }
    }

    private function loadSubjectMap(): void
    {
        $subjects = Subject::all();
        foreach ($subjects as $subject) {
            $this->subjectMap[$subject->key] = $subject->id;
        }
    }

    private function countCsvRows(string $filePath): int
    {
        $count = 0;
        $handle = fopen($filePath, 'r');
        fgetcsv($handle); // Skip header
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        fclose($handle);
        return $count;
    }

    private function processChunks(string $filePath, int $chunkSize): void
    {
        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle); // Get header row

        // Resume from last processed line if job was interrupted
        $lineNumber = 1;
        $resumeFrom = $this->job->last_processed_line ?? 0;

        if ($resumeFrom > 0) {
            $this->info("Resuming from line {$resumeFrom}");
            while ($lineNumber <= $resumeFrom && fgetcsv($handle) !== false) {
                $lineNumber++;
            }
        }

        $chunk = [];

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            $chunk[] = ['line' => $lineNumber, 'data' => $row];

            if (count($chunk) >= $chunkSize) {
                $this->processChunk($chunk, $header);

                // Update resume point after successful chunk
                $this->job->update(['last_processed_line' => $lineNumber]);

                $chunk = [];
            }
        }

        // Process remaining rows
        if (count($chunk) > 0) {
            $this->processChunk($chunk, $header);
            $this->job->update(['last_processed_line' => $lineNumber]);
        }

        fclose($handle);
    }

    private function processChunk(array $chunk, array $header): void
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                DB::transaction(function () use ($chunk, $header) {
                    $students = [];
                    $scores = [];

                    foreach ($chunk as $item) {
                        $lineNumber = $item['line'];
                        $row = $item['data'];

                        try {
                            $validated = $this->validateRow($row, $header, $lineNumber);
                            if ($validated) {
                                $students[] = $validated['student'];
                                $scores = array_merge($scores, $validated['scores']);
                            }
                        } catch (\Exception $e) {
                            // Stream error to file immediately (no memory leak)
                            $this->logError($lineNumber, implode(',', $row), $e->getMessage());
                            $this->job->increment('error_rows');
                        }
                    }

                    // Batch upsert students
                    if (count($students) > 0) {
                        $this->upsertStudents($students);
                    }

                    // Batch upsert scores
                    if (count($scores) > 0) {
                        $this->upsertScores($scores);
                    }

                    $this->job->increment('processed_rows', count($chunk));
                });

                // Success - break retry loop
                break;

            } catch (\Illuminate\Database\QueryException $e) {
                $attempt++;

                if ($attempt >= $maxRetries) {
                    $this->error("Chunk failed after {$maxRetries} attempts");
                    throw $e; // Give up and fail
                }

                // Exponential backoff: 1s, 2s, 4s
                $waitTime = pow(2, $attempt);
                $this->warn("Database error, retrying in {$waitTime}s... (Attempt {$attempt}/{$maxRetries})");
                $this->warn("Error: " . $e->getMessage());
                sleep($waitTime);
            }
        }

        $this->info("Processed chunk: {$this->job->processed_rows}/{$this->job->total_rows}");

        // Small delay to prevent overwhelming database
        usleep(10000); // 10ms
    }

    private function validateRow(array $row, array $header, int $lineNumber): ?array
    {
        $data = array_combine($header, $row);

        if (empty($data['sbd'])) {
            throw new \Exception("Missing SBD");
        }

        // Prepare student data
        $student = [
            'sbd' => $data['sbd'],
            'ma_ngoai_ngu' => $data['ma_ngoai_ngu'] ?? null,
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
        foreach (['toan', 'ngu_van', 'ngoai_ngu', 'vat_li', 'hoa_hoc', 'sinh_hoc', 'lich_su', 'dia_li', 'gdcd'] as $subjectKey) {
            if (isset($data[$subjectKey]) && isset($this->subjectMap[$subjectKey])) {
                $score = $this->parseScore($data[$subjectKey]);
                if ($score !== null) {
                    $scores[] = [
                        'sbd' => $data['sbd'],
                        'subject_key' => $subjectKey,
                        'subject_id' => $this->subjectMap[$subjectKey],
                        'score' => $score,
                    ];
                }
            }
        }

        return ['student' => $student, 'scores' => $scores];
    }

    private function parseScore($value): ?float
    {
        if ($value === null || $value === '' || strtoupper($value) === 'NA') {
            return null;
        }

        $score = (float) $value;

        if ($score < 0 || $score > 10) {
            throw new \Exception("Score out of range: {$value}");
        }

        return $score;
    }

    private function upsertStudents(array $students): void
    {
        $now = now();

        $values = array_map(function($student) use ($now) {
            return [
                'sbd' => $student['sbd'],
                'ma_ngoai_ngu' => $student['ma_ngoai_ngu'],
                'group_a_score' => $student['group_a_score'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $students);

        DB::table('students')->upsert(
            $values,
            ['sbd'], // Unique key
            ['ma_ngoai_ngu', 'group_a_score', 'updated_at'] // Update these columns
        );
    }

    private function upsertScores(array $scores): void
    {
        // Batch fetch student IDs
        $sbds = array_unique(array_column($scores, 'sbd'));
        $studentMap = Student::whereIn('sbd', $sbds)->pluck('id', 'sbd')->toArray();

        $now = now();
        $values = [];

        foreach ($scores as $scoreData) {
            $studentId = $studentMap[$scoreData['sbd']] ?? null;
            if ($studentId) {
                $values[] = [
                    'student_id' => $studentId,
                    'subject_id' => $scoreData['subject_id'],
                    'score' => $scoreData['score'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (count($values) > 0) {
            // PostgreSQL batch upsert - single query
            DB::table('scores')->upsert(
                $values,
                ['student_id', 'subject_id'], // Unique composite key
                ['score', 'updated_at'] // Update these columns
            );
        }
    }

    private function initErrorLog(): void
    {
        $this->errorLogFile = storage_path("logs/import_errors_{$this->job->id}.csv");
        $handle = fopen($this->errorLogFile, 'w');
        fputcsv($handle, ['line_number', 'raw_data', 'error_reason']);
        fclose($handle);
    }

    private function logError(int $lineNumber, string $rawData, string $reason): void
    {
        // Append mode - write immediately to prevent memory leak
        $handle = fopen($this->errorLogFile, 'a');
        fputcsv($handle, [$lineNumber, $rawData, $reason]);
        fclose($handle);
    }
}
