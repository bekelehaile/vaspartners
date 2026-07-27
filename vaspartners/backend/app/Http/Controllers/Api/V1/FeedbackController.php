<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        /** @var \App\Models\Contact $contact */
        $contact = $request->user();

        $items = Feedback::query()
            ->where('contact_id', $contact->id)
            ->with(['company:id,public_id,name,tin'])
            ->orderByDesc('year')
            ->orderByDesc('quarter')
            ->get()
            ->map(fn (Feedback $row) => $this->serialize($row));

        $year = Feedback::currentYear();
        $quarter = Feedback::currentQuarter();
        $current = $items->first(
            fn (array $row) => $row['year'] === $year && $row['quarter'] === $quarter
        );

        return response()->json([
            'data' => [
                'current' => [
                    'year' => $year,
                    'quarter' => $quarter,
                    'label' => 'Q'.$quarter.' '.$year,
                    'feedback' => $current,
                    'can_submit' => true,
                ],
                'items' => $items->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        /** @var \App\Models\Contact $contact */
        $contact = $request->user();

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'year' => ['sometimes', 'integer', 'min:2020', 'max:2100'],
            'quarter' => ['sometimes', 'integer', Rule::in([1, 2, 3, 4])],
        ]);

        $year = (int) ($data['year'] ?? Feedback::currentYear());
        $quarter = (int) ($data['quarter'] ?? Feedback::currentQuarter());

        // Partners may only submit or update the current quarter.
        if ($year !== Feedback::currentYear() || $quarter !== Feedback::currentQuarter()) {
            return response()->json([
                'message' => 'You can only submit feedback for the current quarter.',
            ], 422);
        }

        $feedback = Feedback::query()->updateOrCreate(
            [
                'contact_id' => $contact->id,
                'year' => $year,
                'quarter' => $quarter,
            ],
            [
                'company_id' => $contact->current_company_id,
                'rating' => $data['rating'],
                'description' => $data['description'],
            ]
        );

        $feedback->load(['company:id,public_id,name,tin']);

        return response()->json([
            'data' => $this->serialize($feedback),
            'message' => $feedback->wasRecentlyCreated
                ? 'Feedback submitted for '.$feedback->quarterLabel().'.'
                : 'Feedback updated for '.$feedback->quarterLabel().'.',
        ], $feedback->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function serialize(Feedback $row): array
    {
        return [
            'public_id' => $row->public_id,
            'year' => $row->year,
            'quarter' => $row->quarter,
            'label' => $row->quarterLabel(),
            'rating' => $row->rating,
            'description' => $row->description,
            'company' => $row->company ? [
                'public_id' => $row->company->public_id,
                'name' => $row->company->name,
                'tin' => $row->company->tin,
            ] : null,
            'submitted_at' => $row->updated_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
