<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketRoutingRun extends Model
{
    protected $table = 'support_ticket_routing_runs';

    protected $fillable = [
        'ticket_id',
        'trigger',
        'used_ai',
        'fallback_reason',
        'triage_json',
        'matched_rule_ids_json',
        'candidate_scores_json',
        'winner_user_id',
        'winner_score',
        'summary_json',
    ];

    protected $casts = [
        'ticket_id' => 'int',
        'used_ai' => 'bool',
        'winner_user_id' => 'int',
        'winner_score' => 'float',
    ];
}
