<?php

namespace Tests\Feature;

use App\Models\Sheet;
use App\Models\SheetSourceColumn;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\SourceTermComment;
use App\Models\SuggestedTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SdoExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sdo_export_lists_flagged_terms_send_first(): void
    {
        $sheet = Sheet::create(['name' => 'Radiology Exam']);
        SheetSourceColumn::create(['sheet_id' => $sheet->id, 'spot' => 1, 'vocabulary' => 'Cerner Code', 'code_label' => 'Cerner Alias', 'desc_label' => 'Cerner Name']);

        // to-send, high usage
        $send = SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 900, 'exclude_status' => 'SDO Submission - Send']);
        SourceTermCode::create(['source_term_id' => $send->id, 'spot' => 1, 'code' => 'RX1', 'description' => 'Novel BC-specific scan']);
        SuggestedTarget::create(['source_term_id' => $send->id, 'concept_id' => 4088891]);
        SourceTermComment::create(['source_term_id' => $send->id, 'comment_text' => 'No standard concept; requesting new']);

        // already submitted
        $pending = SourceTerm::create(['sheet_id' => $sheet->id, 'total_count' => 100, 'exclude_status' => 'SDO Submitted - Pending']);
        SourceTermCode::create(['source_term_id' => $pending->id, 'spot' => 1, 'code' => 'RX2', 'description' => 'Awaiting SDO decision']);

        // an unrelated included term must NOT appear
        $included = SourceTerm::create(['sheet_id' => $sheet->id]);
        SourceTermCode::create(['source_term_id' => $included->id, 'spot' => 1, 'code' => 'RX3', 'description' => 'Normal mapped term']);

        $user = User::factory()->create(['enabled' => true, 'is_mapper' => true]);
        $csv = $this->actingAs($user)->get('/export/sdo')->streamedContent();

        $this->assertStringContainsString('sheet,sdo_status,source_vocabulary,source_code,source_description,usage_count,suggested_concept_id,comment', $csv);
        $this->assertStringContainsString('RX1', $csv);
        $this->assertStringContainsString('To send', $csv);
        $this->assertStringContainsString('Submitted - pending', $csv);
        $this->assertStringContainsString('No standard concept; requesting new', $csv);
        $this->assertStringNotContainsString('RX3', $csv);

        // "To send" row precedes "Submitted - pending"
        $this->assertLessThan(strpos($csv, 'RX2'), strpos($csv, 'RX1'));
    }

    public function test_sdo_export_requires_auth(): void
    {
        $this->get('/export/sdo')->assertRedirect('/login');
    }
}
