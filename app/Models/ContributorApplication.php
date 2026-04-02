<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ContributorApplication extends Model
{
    protected $fillable = [
        'user_id','full_name','email','phone','writing_experience',
        'sample_article_url','topics_of_interest','preferred_language',
        'wants_payment','status','reviewed_by_user_id','review_notes','reviewed_at',
    ];
    protected $casts = ['wants_payment'=>'boolean','reviewed_at'=>'datetime'];
}
