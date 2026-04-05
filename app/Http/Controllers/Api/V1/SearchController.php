<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Resources\ArticleResource;
use Illuminate\Support\Facades\DB;
class SearchController extends Controller {
    public function index(Request $request): JsonResponse {
        $q = $request->get('q', '');
        $langId = DB::table('languages')->where('code', $request->get('lang','en'))->value('id') ?? 1;
        $channelId = DB::table('channels')->where('slug','cni-news')->value('id') ?? 1;
        if (strlen($q) < 2) return response()->json(['data'=>[],'meta'=>['total'=>0]]);
        $ids = ArticleTranslation::where('title','like',"%{$q}%")->pluck('article_id');
        $articles = Article::with(['translations'=>fn($q2)=>$q2->where('language_id',$langId),'mainCategory','author','featuredImage'])->published()->forChannel($channelId)->whereIn('i
  d',$ids)->orderByDesc('published_at')->paginate(15);
    }
}
