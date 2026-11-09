<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Services\DailySummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class DailyReportController extends Controller
{
    public function index(Request $request): JsonResponse { return response()->json(DailyReport::where('user_id',$request->user()->id)->with('feedback')->latest('report_date')->paginate(15)); }
    public function store(Request $request, DailySummaryService $summaries): JsonResponse {
        $data=$request->validate(['date'=>['sometimes','required','date_format:Y-m-d'],'work_summary'=>['nullable','string','max:5000'],'challenges'=>['nullable','string','max:5000'],'learning'=>['nullable','string','max:5000'],'rating'=>['required','integer','between:1,5'],'reflection'=>['nullable','string','max:5000']]);
        $date=$data['date']??now(config('app.timezone'))->toDateString();
        if($date!==now(config('app.timezone'))->toDateString()) throw ValidationException::withMessages(['date'=>'Daily reflections can only be submitted for today.']);
        $report = DailyReport::query()->firstOrCreate(
            ['user_id' => $request->user()->id, 'report_date' => $date],
            array_merge($summaries->generate($request->user()->id, $date), [
                'work_summary' => $data['work_summary'] ?? null,
                'challenges' => $data['challenges'] ?? null,
                'learning' => $data['learning'] ?? null,
                'rating' => $data['rating'],
                'reflection' => $data['reflection'] ?? null,
                'review_status' => 'pending',
                'finalized_at' => now(config('app.timezone')),
            ])
        );

        if (! $report->wasRecentlyCreated) {
            throw ValidationException::withMessages([
                'date' => 'Daily reports cannot be edited after they have been finalized.',
            ]);
        }

        return response()->json(['data'=>$report->load('feedback')],201);
    }
    public function show(Request $request, DailyReport $report): JsonResponse { $user=$request->user(); abort_unless($report->user_id===$user->id||$user->is_admin||$user->role==='admin',403); return response()->json(['data'=>$report->load('user:id,name,email','feedback')]); }
}
