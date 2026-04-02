<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SecurityIncident extends Model {
    protected $fillable = ['reported_by_user_id','employee_id','employee_card_id','type',
        'description','location','incident_time','severity','status',
        'resolved_by_user_id','resolved_at','resolution_notes'];
    protected $casts = ['incident_time'=>'datetime','resolved_at'=>'datetime'];
    public function reportedBy() { return $this->belongsTo(User::class,'reported_by_user_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function employeeCard() { return $this->belongsTo(EmployeeCard::class); }
}
