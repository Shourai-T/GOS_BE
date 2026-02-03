<?php

namespace Tests\Unit;

use App\Services\CsvRowValidator;
use PHPUnit\Framework\TestCase;

class CsvRowValidatorTest extends TestCase
{
    private CsvRowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock subject map: key => id
        $this->validator = new CsvRowValidator([
            'toan' => 1,
            'vat_li' => 2,
            'hoa_hoc' => 3,
            'ngu_van' => 4
        ]);
    }

    public function test_validate_valid_row_calculates_group_a()
    {
        $header = ['sbd', 'toan', 'vat_li', 'hoa_hoc', 'ma_ngoai_ngu'];
        $row = ['123456', '8.00', '7.5', '9', 'N1'];

        $result = $this->validator->validateRow($row, $header, 1);

        $this->assertEquals('123456', $result['student']['sbd']);
        $this->assertEquals(24.5, $result['student']['group_a_score']); // 8 + 7.5 + 9
        $this->assertEquals('N1', $result['student']['ma_ngoai_ngu']);
        $this->assertCount(3, $result['scores']);
    }

    public function test_validate_missing_group_a_component_returns_null()
    {
        // Missing Chemistry (hoa_hoc)
        $header = ['sbd', 'toan', 'vat_li'];
        $row = ['123456', '8.00', '7.5'];

        $result = $this->validator->validateRow($row, $header, 1);

        $this->assertNull($result['student']['group_a_score']);
    }

    public function test_parse_score_validates_range()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Score out of range: 11");

        $this->validator->parseScore("11");
    }

    public function test_parse_score_handles_na()
    {
        $this->assertNull($this->validator->parseScore("NA"));
        $this->assertNull($this->validator->parseScore(""));
        $this->assertNull($this->validator->parseScore(null));
    }
    public function test_validate_throws_exception_if_missing_sbd()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Missing SBD");

        $header = ['toan'];
        $row = ['8']; // No SBD column

        $this->validator->validateRow($row, $header, 1);
    }

    public function test_parse_score_returns_null_for_non_numeric()
    {
        $this->assertNull($this->validator->parseScore("ABC"));
        $this->assertNull($this->validator->parseScore("12.5.5")); // Invalid float
    }
}
