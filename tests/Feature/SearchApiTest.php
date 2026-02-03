<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Subject;
use App\Models\Score;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    // Use RefreshDatabase to reset DB after each test
    // Assuming configured in phpunit.xml to use sqlite :memory: or testing db
    // If not, we should be careful. Usually standard Laravel sets this up.
    use RefreshDatabase;

    public function test_search_returns_student_data()
    {
        // Seed data
        $student = Student::create(['sbd' => '20000001', 'group_a_score' => 25.5]);
        $subject = Subject::create(['key' => 'toan', 'name' => 'Toán']);
        Score::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'score' => 9.0]);

        // Act
        $response = $this->postJson('/api/search', ['sbd' => '20000001']);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'sbd' => '20000001',
                    'group_a_score' => 25.50, // Decimal cast might return string or float depending on driver
                    'scores' => [
                        'toan' => 9.00
                    ]
                ]
            ]);
    }

    public function test_search_returns_404_if_not_found()
    {
        $response = $this->postJson('/api/search', ['sbd' => '99999999']);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Không tìm thấy thí sinh với SBD này'
            ]);
    }

    public function test_search_validation_error()
    {
        // Missing SBD
        $response = $this->postJson('/api/search', []);

        $response->assertStatus(422) // Unprocessable Entity
            ->assertJsonValidationErrors(['sbd']);
    }
}
