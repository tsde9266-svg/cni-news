<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmployeeCard extends Model {
    protected $fillable = ['employee_id','card_number','card_type','issue_date','expiry_date',
        'card_front_media_id','qr_code_data','status'];
    protected $casts = ['issue_date'=>'date','expiry_date'=>'date'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function accessZones(): BelongsToMany {
        return $this->belongsToMany(EmployeeCardAccessZone::class,'employee_card_zone_map',
            'employee_card_id','access_zone_id')->withPivot('granted_at','granted_by_user_id');
    }
    public function isValid(): bool {
        return $this->status === 'active' && $this->expiry_date->isFuture();
    }
}
