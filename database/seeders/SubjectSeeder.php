<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subjects = [
            ['key' => 'toan', 'name' => 'Toán'],
            ['key' => 'ngu_van', 'name' => 'Ngữ văn'],
            ['key' => 'ngoai_ngu', 'name' => 'Ngoại ngữ'],
            ['key' => 'vat_li', 'name' => 'Vật lý'],
            ['key' => 'hoa_hoc', 'name' => 'Hóa học'],
            ['key' => 'sinh_hoc', 'name' => 'Sinh học'],
            ['key' => 'lich_su', 'name' => 'Lịch sử'],
            ['key' => 'dia_li', 'name' => 'Địa lý'],
            ['key' => 'gdcd', 'name' => 'GDCD'],
        ];

        foreach ($subjects as $subject) {
            Subject::firstOrCreate(
                ['key' => $subject['key']],
                ['name' => $subject['name']]
            );
        }
    }
}
