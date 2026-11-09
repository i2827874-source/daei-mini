<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\ScheduledActivity;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DailySummaryService
{
    public function generate(int|string $userId, string $date): array
    {
        $day = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        $now = now(config('app.timezone'));
        $counts = $this->emptyCounts();
        $prayers = $this->emptyCounts();
        $habits = $this->emptyCounts();
        $activities = [];

        $scheduledActivities = ScheduledActivity::query()
            ->where('user_id', $userId)
            ->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('starts_at')
            ->get();

        foreach ($scheduledActivities as $activity) {
            $status = $activity->completed_at !== null
                ? 'completed'
                : ($activity->starts_at->isBefore($now) ? 'missed' : 'pending');

            $this->incrementCount($counts, $status);

            $type = strtolower($activity->type);
            if ($type === 'prayer') {
                $this->incrementCount($prayers, $status);
            } elseif ($type === 'habit') {
                $this->incrementCount($habits, $status);
            }

            $activities[] = [
                'id' => $activity->getKey(),
                'type' => $activity->type,
                'title' => $activity->title,
                'starts_at' => $activity->starts_at?->toIso8601String(),
                'completed_at' => $activity->completed_at?->toIso8601String(),
                'status' => $status,
            ];
        }

        return [
            'report_date' => $day->toDateString(),
            'total_activities' => $counts['total'],
            'completed_activities' => $counts['completed'],
            'pending_activities' => $counts['pending'],
            'missed_activities' => $counts['missed'],
            'prayer_stats' => $prayers,
            'habit_stats' => $habits,
            'activities' => $activities,
        ];
    }

    public function finalize(int|string $userId, string $date): DailyReport
    {
        $today = now(config('app.timezone'))->toDateString();
        if ($date !== $today) {
            throw ValidationException::withMessages([
                'date' => 'Daily summaries can only be submitted or edited for the current date.',
            ]);
        }

        $summary = $this->generate($userId, $date);

        $report = DailyReport::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'report_date' => $summary['report_date'],
            ],
            array_merge($summary, [
                'finalized_at' => now(config('app.timezone')),
            ])
        );

        if (! $report->wasRecentlyCreated) {
            throw ValidationException::withMessages([
                'date' => 'Daily summaries cannot be edited after they have been finalized.',
            ]);
        }

        return $report;
    }

    private function emptyCounts(): array
    {
        return [
            'total' => 0,
            'completed' => 0,
            'pending' => 0,
            'missed' => 0,
        ];
    }

    private function incrementCount(array &$counts, string $status): void
    {
        $counts['total']++;
        $counts[$status]++;
    }
}
