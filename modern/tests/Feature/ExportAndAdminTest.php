<?php

namespace Tests\Feature;

use App\Livewire\ReleasesAdmin;
use App\Livewire\UserAdmin;
use App\Models\Concept;
use App\Models\MapEntry;
use App\Models\Sheet;
use App\Models\SheetSourceColumn;
use App\Models\SourceTerm;
use App\Models\SourceTermCode;
use App\Models\User;
use App\Services\ReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ExportAndAdminTest extends TestCase
{
    use RefreshDatabase;

    private function seedMaps(): Sheet
    {
        $sheet = Sheet::create(['name' => 'Location']);
        SheetSourceColumn::create(['sheet_id' => $sheet->id, 'spot' => 1, 'vocabulary' => 'Cerner Code', 'code_label' => 'Location Code', 'desc_label' => 'Location Description']);

        Concept::create(['concept_id' => 8717, 'concept_name' => 'Inpatient Hospital', 'domain_id' => 'Visit', 'vocabulary_id' => 'CMS Place of Service', 'concept_class_id' => 'Visit', 'standard_concept' => 'S', 'concept_code' => '21', 'valid_start_date' => '19700101', 'valid_end_date' => '20991231']);

        // linked map (source from term codes)
        $term = SourceTerm::create(['sheet_id' => $sheet->id]);
        SourceTermCode::create(['source_term_id' => $term->id, 'spot' => 1, 'code' => 'LGH', 'description' => 'Lions Gate']);
        MapEntry::create(['source_term_id' => $term->id, 'target_concept_id' => 8717, 'target_concept_name' => 'Inpatient Hospital', 'target_vocabulary_id' => 'CMS Place of Service']);

        // unlinked map (source from snapshot)
        MapEntry::create(['source_term_id' => null, 'source_code' => 'OLD1', 'source_vocabulary_id' => 'Cerner Code', 'source_code_description' => 'Retired site', 'target_concept_id' => 8717, 'target_concept_name' => 'Inpatient Hospital', 'target_vocabulary_id' => 'CMS Place of Service']);

        return $sheet;
    }

    private function admin(): User
    {
        return User::factory()->create(['enabled' => true, 'is_portal_admin' => true]);
    }

    public function test_stcm_export_covers_linked_and_unlinked_maps(): void
    {
        $this->seedMaps();

        $csv = $this->actingAs($this->admin())->get('/export/stcm')->streamedContent();

        $this->assertStringContainsString('source_code,source_concept_id,source_vocabulary_id', $csv);
        $this->assertStringContainsString('LGH', $csv);        // linked, code from term
        $this->assertStringContainsString('OLD1', $csv);       // unlinked, code from snapshot
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(3, $lines); // header + 2 maps
    }

    public function test_create_release_freezes_current_maps_and_exports(): void
    {
        $this->seedMaps();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(ReleasesAdmin::class)
            ->set('name', 'v1')
            ->call('createRelease')
            ->assertSee('created with 2 maps');

        $this->assertDatabaseHas('releases', ['name' => 'v1', 'map_count' => 2]);
        $releaseId = DB::table('releases')->where('name', 'v1')->value('id');

        $csv = $this->actingAs($admin)->get("/export/stcm/$releaseId")->streamedContent();
        $this->assertStringContainsString('LGH', $csv);
        $this->assertStringContainsString('OLD1', $csv);
    }

    public function test_user_admin_toggles_role_and_audits(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['name' => 'New User', 'enabled' => true, 'is_mapper' => false]);

        Livewire::actingAs($admin)->test(UserAdmin::class)
            ->call('toggle', $target->id, 'is_mapper')
            ->assertSee('Updated is_mapper');

        $this->assertTrue($target->fresh()->is_mapper);
        $this->assertDatabaseHas('user_audits', [
            'user_id' => $target->id, 'field' => 'is_mapper', 'new_value' => '1', 'changed_by' => $admin->name,
        ]);
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(UserAdmin::class)
            ->call('toggle', $admin->id, 'is_portal_admin')
            ->assertSee('cannot remove your own admin');

        $this->assertTrue($admin->fresh()->is_portal_admin);
    }

    public function test_admin_pages_forbidden_for_non_admin(): void
    {
        $mapper = User::factory()->create(['enabled' => true, 'is_mapper' => true]);
        Livewire::actingAs($mapper)->test(ReleasesAdmin::class)->assertForbidden();
        Livewire::actingAs($mapper)->test(UserAdmin::class)->assertForbidden();
    }

    public function test_exports_require_auth(): void
    {
        $this->get('/export/stcm')->assertRedirect('/login');
    }
}
