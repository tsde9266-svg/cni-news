<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EmployeeCardAccessZone extends Model {
    protected $fillable = ['name','description','location'];
    public function cards() {
        return $this->belongsToMany(EmployeeCard::class,'employee_card_zone_map','access_zone_id','employee_card_id');
    }
}
