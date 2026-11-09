<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\Group;
use App\Models\ScheduledActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyRoutineModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_people_and_group_endpoints_are_protected_and_return_details(): void
    {
        $admin=User::factory()->create(['is_admin'=>true]); $member=User::factory()->create();
        $group=Group::create(['name'=>'Morning Circle']); $group->members()->attach($member);
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('stats.users',2)->assertJsonPath('stats.groups',1);
        $this->getJson('/api/admin/users')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/admin/users/'.$member->id)->assertOk()->assertJsonPath('groups.0.name','Morning Circle');
        $this->getJson('/api/admin/groups')->assertOk()->assertJsonPath('data.0.members.0.email',$member->email);
        $this->getJson('/api/admin/groups/'.$group->id)->assertOk()->assertJsonPath('members_count',1);
    }

    public function test_user_can_submit_history_view_and_read_own_daily_assessment(): void
    {
        $user=User::factory()->create(); Sanctum::actingAs($user);
        ScheduledActivity::create(['user_id'=>$user->id,'type'=>'habit','title'=>'Quran recitation','starts_at'=>now()->subHour(),'completed_at'=>now()->subMinutes(20)]);
        ScheduledActivity::create(['user_id'=>$user->id,'type'=>'habit','title'=>'Exercise','starts_at'=>now()->addHour()]);
        $data=['date'=>now(config('app.timezone'))->toDateString(),'work_summary'=>'Completed key tasks.','challenges'=>'Busy morning.','learning'=>'Plan breaks.','rating'=>4,'reflection'=>'Keep a steady pace.'];
        $response=$this->postJson('/api/daily-reports',$data)->assertCreated()->assertJsonPath('data.total_activities',2)->assertJsonPath('data.completed_activities',1)->assertJsonPath('data.rating',4);
        $id=$response->json('data.id');
        $this->getJson('/api/daily-reports')->assertOk()->assertJsonPath('data.0.reflection','Keep a steady pace.');
        $this->getJson('/api/daily-reports/'.$id)->assertOk()->assertJsonPath('data.id',$id);
        $this->postJson('/api/daily-reports', array_merge($data, [
            'reflection' => 'Changed after submission.',
        ]))->assertUnprocessable();
        $this->assertDatabaseHas('daily_reports', [
            'id' => $id,
            'reflection' => 'Keep a steady pace.',
        ]);
        $this->postJson('/api/daily-reports',['rating'=>8])->assertUnprocessable();
    }

    public function test_finalized_daily_summary_cannot_be_submitted_again(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/daily-summary')->assertOk();
        $this->putJson('/api/daily-summary')->assertUnprocessable();
    }

    public function test_admin_can_filter_reports_view_details_send_feedback_and_change_status(): void
    {
        $admin=User::factory()->create(['is_admin'=>true]); $member=User::factory()->create();
        $report=DailyReport::create(['user_id'=>$member->id,'report_date'=>now()->toDateString(),'total_activities'=>1,'completed_activities'=>1,'pending_activities'=>0,'missed_activities'=>0,'prayer_stats'=>['total'=>0,'completed'=>0,'pending'=>0,'missed'=>0],'habit_stats'=>['total'=>1,'completed'=>1,'pending'=>0,'missed'=>0],'activities'=>[['id'=>1,'title'=>'Walk','status'=>'completed']],'finalized_at'=>now(),'rating'=>5,'review_status'=>'pending']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/reports?user_id='.$member->id.'&status=pending')->assertOk()->assertJsonPath('data.0.id',$report->id);
        $this->getJson('/api/admin/reports/'.$report->id)->assertOk()->assertJsonPath('data.activities.0.title','Walk');
        $this->postJson('/api/admin/reports/'.$report->id.'/feedback',['comment'=>'Well done.','suggestions'=>'Keep it up.'])->assertCreated();
        $this->getJson('/api/admin/reports/'.$report->id)->assertOk()->assertJsonPath('data.feedback.0.suggestions','Keep it up.');
        $this->patchJson('/api/admin/reports/'.$report->id.'/status',['status'=>'pending'])->assertOk()->assertJsonPath('data.review_status','pending');
    }

    public function test_regular_user_is_denied_admin_access_and_other_users_reports(): void
    {
        $user=User::factory()->create();$other=User::factory()->create();
        $report=DailyReport::create(['user_id'=>$other->id,'report_date'=>now()->toDateString(),'total_activities'=>0,'completed_activities'=>0,'pending_activities'=>0,'missed_activities'=>0,'prayer_stats'=>[],'habit_stats'=>[],'activities'=>[],'finalized_at'=>now()]);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/dashboard')->assertForbidden();
        $this->getJson('/api/daily-reports/'.$report->id)->assertForbidden();
    }
}
