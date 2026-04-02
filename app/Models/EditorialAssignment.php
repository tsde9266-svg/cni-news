<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EditorialAssignment extends Model {
    protected $fillable = ['channel_id','created_by_user_id','assigned_to_user_id','title',
        'description','category_id','priority','due_at','status','related_article_id'];
    protected $casts = ['due_at'=>'datetime'];
    public function assignedTo() { return $this->belongsTo(User::class,'assigned_to_user_id'); }
    public function createdBy() { return $this->belongsTo(User::class,'created_by_user_id'); }
    public function category() { return $this->belongsTo(Category::class); }
    public function relatedArticle() { return $this->belongsTo(Article::class,'related_article_id'); }
}
