<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model {
    protected $fillable = ['user_id','employee_code','department','designation',
        'line_manager_user_id','date_joined','date_left','status','contract_type'];
    protected $casts = ['date_joined'=>'date','date_left'=>'date'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function lineManager(): BelongsTo { return $this->belongsTo(User::class,'line_manager_user_id'); }
    public function cards(): HasMany { return $this->hasMany(EmployeeCard::class); }
    public function activeCard(): ?EmployeeCard {
        return $this->cards()->where('status','active')->where('expiry_date','>',now())->latest()->first();
    }
}
