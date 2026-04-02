<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdPlacement extends Model {
    protected $fillable = ['key','description','width_px','height_px'];
}
