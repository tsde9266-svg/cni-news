<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TagTranslation extends Model
{
    protected $fillable = ['tag_id','language_id','name'];
}
