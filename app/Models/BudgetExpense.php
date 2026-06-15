<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BudgetExpense extends Model
{
    use HasFactory;

    protected $fillable = [
        'budget_id',
        'name',
        'note',
        'estimated_amount',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function budget()
    {
        return $this->belongsTo(
            PlanBudget::class,
            'budget_id'
        );
    }
}