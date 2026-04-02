<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * CNI News Network — Demo Data Seeder (v3 — Final)
 *
 * Fixes vs previous version:
 *  FIX A: event_types - uses individual inserts (NOT updateOrInsert) because
 *         the actual table structure caused issues. Checks existence by ID.
 *  FIX B: article_versions - removed 'updated_at' (table has no such column,
 *         only 'created_at' as useCurrent() timestamp)
 *  FIX C: live_streams batch insert - split into individual inserts so each
 *         row can have different columns without column count mismatch
 *  FIX D: promo_codes batch insert - split into individual inserts so NULL
 *         values in optional columns don't break the batch column alignment
 *
 * Run: php artisan db:seed --class=DemoDataSeeder
 * Safe to re-run: all inserts check for existence first
 */
class DemoDataSeeder extends Seeder
{
    private int   $channelId;
    private int   $enLangId;
    private array $categoryIds = [];
    private array $tagIds      = [];
    private array $userIds     = [];

    public function run(): void
    {
        $this->channelId = DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
        $this->enLangId  = DB::table('languages')->where('code', 'en')->value('id') ?? 1;

        $this->command->info('🌱 CNI Demo Data Seeder v3');

        $this->step('Event Types',  fn() => $this->seedEventTypes());
        $this->step('Categories',   fn() => $this->seedCategories());
        $this->step('Tags',         fn() => $this->seedTags());
        $this->step('Users',        fn() => $this->seedUsers());
        $this->step('Articles',     fn() => $this->seedArticles());
        $this->step('Memberships',  fn() => $this->seedMemberships());
        $this->step('Live Streams', fn() => $this->seedLiveStreams());
        $this->step('Events',       fn() => $this->seedEvents());
        $this->step('Promo Codes',  fn() => $this->seedPromoCodes());
        $this->step('Comments',     fn() => $this->seedComments());

        $this->command->info('✅ Done!');
        $this->command->table(['Table', 'Rows'], [
            ['categories',           DB::table('categories')->count()],
            ['tags',                 DB::table('tags')->count()],
            ['users',                DB::table('users')->count()],
            ['author_profiles',      DB::table('author_profiles')->count()],
            ['articles',             DB::table('articles')->count()],
            ['article_translations', DB::table('article_translations')->count()],
            ['memberships',          DB::table('memberships')->count()],
            ['live_streams',         DB::table('live_streams')->count()],
            ['events',               DB::table('events')->count()],
            ['promo_codes',          DB::table('promo_codes')->count()],
            ['comments',             DB::table('comments')->count()],
        ]);

        $this->command->info('Logins (password: Demo1234!):');
        $this->command->table(['Role','Email'], [
            ['Super Admin', 'admin@cni.co.uk'],
            ['Editor',      'editor@cni.co.uk'],
            ['Reporter',    'tariq@cni.co.uk'],
            ['Reporter',    'aisha@cni.co.uk'],
            ['Reporter',    'hassan@cni.co.uk'],
            ['Member(Free)','member1@example.com'],
            ['Member(Gold)','member2@example.com'],
        ]);
    }

    private function step(string $name, callable $fn): void
    {
        $this->command->info("  ► {$name}...");
        try { $fn(); }
        catch (\Exception $e) {
            $this->command->error("  ✗ {$name}: " . $e->getMessage());
        }
    }

    // FIX A: individual inserts, no updateOrInsert (avoids column detection issues)
    private function seedEventTypes(): void
    {
        $types = [
            'Cultural',
            'Community',
            'Press Conference',
            'Fundraiser',
            'Sports',
            'Musical',
            'Religious',
            'Political',
        ];

        foreach ($types as $typeName) {
            // Only insert if this type is not already present
            if (!DB::table('event_types')->where('name', $typeName)->exists()) {
                DB::table('event_types')->insert([
                    'name'        => $typeName,
                    'description' => $typeName . ' events',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }



    private function seedCategories(): void
    {
        $cats = [
            ['slug'=>'pakistan',   'name'=>'Pakistan',   'desc'=>'Latest news from Pakistan',      'pos'=>1,  'feat'=>true],
            ['slug'=>'kashmir',    'name'=>'Kashmir',    'desc'=>'Kashmir news and updates',       'pos'=>2,  'feat'=>true],
            ['slug'=>'world',      'name'=>'World',      'desc'=>'International news',             'pos'=>3,  'feat'=>true],
            ['slug'=>'overseas',   'name'=>'Overseas',   'desc'=>'Pakistani diaspora news',        'pos'=>4,  'feat'=>true],
            ['slug'=>'sports',     'name'=>'Sports',     'desc'=>'Cricket, football and more',     'pos'=>5,  'feat'=>false],
            ['slug'=>'technology', 'name'=>'Technology', 'desc'=>'Tech and innovation',            'pos'=>6,  'feat'=>false],
            ['slug'=>'business',   'name'=>'Business',   'desc'=>'Economy and markets',            'pos'=>7,  'feat'=>false],
            ['slug'=>'uk',         'name'=>'UK',         'desc'=>'UK politics and local news',     'pos'=>8,  'feat'=>false],
            ['slug'=>'culture',    'name'=>'Culture',    'desc'=>'Arts, culture and entertainment','pos'=>9,  'feat'=>false],
            ['slug'=>'health',     'name'=>'Health',     'desc'=>'Health and wellbeing',           'pos'=>10, 'feat'=>false],
        ];

        foreach ($cats as $cat) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $cat['slug'], 'channel_id' => $this->channelId],
                ['default_name'=>$cat['name'], 'default_description'=>$cat['desc'],
                 'position'=>$cat['pos'], 'is_active'=>true, 'is_featured'=>$cat['feat'],
                 'created_at'=>now(), 'updated_at'=>now()]
            );
            $id = DB::table('categories')
                ->where('slug', $cat['slug'])->where('channel_id', $this->channelId)->value('id');
            $this->categoryIds[$cat['slug']] = $id;

            DB::table('category_translations')->insertOrIgnore([
                'category_id'=>$id, 'language_id'=>$this->enLangId,
                'name'=>$cat['name'], 'description'=>$cat['desc'],
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
        }
    }

    private function seedTags(): void
    {
        $tags = ['Imran Khan','General Elections','Lahore','Islamabad','Karachi',
                 'AJK','Line of Control','UN','Human Rights','Diaspora',
                 'Cricket','PSL','Manchester','Birmingham','London','Bradford',
                 'Economy','IMF','Climate Change','Education','Health',
                 'Visa','Immigration','Ramadan','Eid'];
        foreach ($tags as $name) {
            $slug = Str::slug($name);
            DB::table('tags')->updateOrInsert(
                ['slug'=>$slug, 'channel_id'=>$this->channelId],
                ['default_name'=>$name, 'created_at'=>now(), 'updated_at'=>now()]
            );
            $this->tagIds[$slug] = DB::table('tags')
                ->where('slug',$slug)->where('channel_id',$this->channelId)->value('id');
        }
    }

    private function seedUsers(): void
    {
        $users = [
            ['email'=>'editor@cni.co.uk',   'first'=>'Sarah',   'last'=>'Ahmed',   'display'=>'Sarah Ahmed',
             'role'=>'editor',    'byline'=>'Editor-in-Chief, CNI News Network',
             'bio'=>'Sarah Ahmed has over 15 years of experience covering South Asian affairs. She joined CNI News as Editor-in-Chief in 2020.',
             'sp'=>true,  'mon'=>false],
            ['email'=>'sarah@cni.co.uk',    'first'=>'Sarah',   'last'=>'Mirza',   'display'=>'Sarah Mirza',
             'role'=>'journalist','byline'=>'World Affairs Correspondent',
             'bio'=>'Sarah Mirza is CNI\'s world affairs correspondent, specialising in Middle East and international relations.',
             'sp'=>false, 'mon'=>true],
            ['email'=>'tariq@cni.co.uk',    'first'=>'Tariq',   'last'=>'Mahmood', 'display'=>'Tariq Mahmood',
             'role'=>'journalist','byline'=>'Pakistan & Kashmir Correspondent',
             'bio'=>'Tariq Mahmood is CNI\'s Pakistan correspondent, based in Islamabad. He specialises in political affairs and Kashmir reporting.',
             'sp'=>false, 'mon'=>true],
            ['email'=>'aisha@cni.co.uk',    'first'=>'Aisha',   'last'=>'Raza',    'display'=>'Aisha Raza',
             'role'=>'journalist','byline'=>'UK & Overseas Reporter',
             'bio'=>'Aisha Raza covers the British Pakistani community and UK politics from Birmingham.',
             'sp'=>false, 'mon'=>true],
            ['email'=>'hassan@cni.co.uk',   'first'=>'Hassan',  'last'=>'Khan',    'display'=>'Hassan Khan',
             'role'=>'journalist','byline'=>'Sports Journalist',
             'bio'=>'Hassan Khan covers Pakistan cricket, PSL, and British South Asian football from Manchester.',
             'sp'=>false, 'mon'=>true],
            ['email'=>'member1@example.com','first'=>'Mohammed','last'=>'Iqbal',   'display'=>'Mohammed Iqbal',
             'role'=>'member',  'byline'=>null,'bio'=>null,'sp'=>false,'mon'=>false],
            ['email'=>'member2@example.com','first'=>'Fatima',  'last'=>'Bibi',    'display'=>'Fatima Bibi',
             'role'=>'member',  'byline'=>null,'bio'=>null,'sp'=>false,'mon'=>false],
            ['email'=>'platinum@example.com','first'=>'Zafar', 'last'=>'Hussain', 'display'=>'Zafar Hussain',
             'role'=>'member',  'byline'=>null,'bio'=>null,'sp'=>false,'mon'=>false],
        ];

        foreach ($users as $u) {
            if (DB::table('users')->where('email',$u['email'])->exists()) {
                $this->userIds[$u['email']] = DB::table('users')->where('email',$u['email'])->value('id');
                continue;
            }
            $uid = DB::table('users')->insertGetId([
                'channel_id'=>$this->channelId, 'email'=>$u['email'],
                'password_hash'=>Hash::make('Demo1234!'),
                'first_name'=>$u['first'], 'last_name'=>$u['last'], 'display_name'=>$u['display'],
                'preferred_language_id'=>$this->enLangId, 'timezone'=>'Europe/London',
                'is_email_verified'=>true, 'status'=>'active',
                'created_at'=>now()->subDays(rand(30,180)), 'updated_at'=>now(),
            ]);
            $this->userIds[$u['email']] = $uid;

            $roleId = DB::table('roles')->where('slug',$u['role'])->value('id');
            if ($roleId) {
                DB::table('user_role_map')->insertOrIgnore([
                    'user_id'=>$uid,'role_id'=>$roleId,'channel_id'=>$this->channelId,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            if ($u['byline']) {
                DB::table('author_profiles')->insertOrIgnore([
                    'user_id'=>$uid,'byline'=>$u['byline'],'bio'=>$u['bio'],
                    'bio_short'=>Str::limit($u['bio']??'',120),
                    'can_self_publish'=>$u['sp'],'is_monetised'=>$u['mon'],
                    'default_rate_type'=>'per_article','default_rate_amount'=>25.00,
                    'rate_currency'=>'GBP','is_active'=>true,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
        }
    }

    private function seedArticles(): void
    {
        // Ensure lookups populated from DB (safe even if seeder sections ran partially)
        if (empty($this->categoryIds)) {
            foreach (DB::table('categories')->where('channel_id',$this->channelId)->get() as $c)
                $this->categoryIds[$c->slug] = $c->id;
        }
        if (empty($this->tagIds)) {
            foreach (DB::table('tags')->where('channel_id',$this->channelId)->get() as $t)
                $this->tagIds[$t->slug] = $t->id;
        }
        if (empty($this->userIds)) {
            foreach (['tariq@cni.co.uk','aisha@cni.co.uk','hassan@cni.co.uk','sarah@cni.co.uk','editor@cni.co.uk'] as $em)
                if ($id = DB::table('users')->where('email',$em)->value('id'))
                    $this->userIds[$em] = $id;
        }

        $articles = [
            ['title'=>'Pakistan Economy Shows Signs of Recovery as IMF Review Concludes',
             'subtitle'=>'Finance Minister announces stabilisation measures after successful talks',
             'summary'=>'Pakistan\'s economy is showing early signs of recovery following the conclusion of the IMF\'s quarterly review, with foreign exchange reserves rising for the third consecutive month.',
             'body'=>'<p>Pakistan\'s battered economy is showing encouraging signs of stabilisation following the successful conclusion of the International Monetary Fund\'s fifth quarterly review of its $3 billion Stand-By Arrangement.</p><p>Finance Minister Muhammad Aurangzeb announced that foreign exchange reserves held by the State Bank of Pakistan have risen to $9.4 billion — a significant improvement from the critically low levels seen in early 2023 when the country teetered on the edge of default.</p><p>"Pakistan is on the right track," said the IMF\'s mission chief. "The authorities have made significant progress on the structural benchmarks agreed under the programme."</p><p>The Pakistani rupee has stabilised against the dollar, trading at around 278 — a marked improvement from its all-time low of 307 in 2023. However, analysts caution that inflation remains elevated at around 24 per cent, placing significant pressure on ordinary households.</p>',
             'cat'=>'pakistan','author'=>'tariq@cni.co.uk','tags'=>['Economy','IMF'],
             'type'=>'news','b'=>false,'f'=>true,'v'=>4521,'d'=>1],

            ['title'=>'Imran Khan Legal Team Files Fresh Appeal as PTI Support Rallies Across Punjab',
             'subtitle'=>'Thousands gather in Lahore as lawyers argue wrongful conviction',
             'summary'=>'Pakistan Tehreek-e-Insaf supporters gathered in cities across Punjab as Imran Khan\'s legal team filed a fresh appeal challenging his conviction.',
             'body'=>'<p>Thousands of Pakistan Tehreek-e-Insaf supporters took to the streets across Punjab as Imran Khan\'s legal team filed a comprehensive appeal challenging his conviction in what PTI describes as a politically motivated prosecution.</p><p>The rallies saw significant turnout in Lahore, Faisalabad, and Multan, as Khan\'s lawyers submitted a 200-page document to the Islamabad High Court arguing that due process had been violated throughout the trial.</p><p>Senior PTI barrister Salman Akram Raja: "This case was decided before it began. We have compelling evidence that shows the entire process was designed to remove a democratically elected leader from politics."</p><p>International human rights organisations including Amnesty International have called for Khan\'s release, citing concerns about the independence of Pakistan\'s judiciary.</p>',
             'cat'=>'pakistan','author'=>'tariq@cni.co.uk','tags'=>['Imran Khan','Lahore'],
             'type'=>'news','b'=>true,'f'=>false,'v'=>8932,'d'=>0],

            ['title'=>'Lahore Smog Crisis Schools Closed as Air Quality Hits Dangerous Levels',
             'subtitle'=>'Punjab government declares environmental emergency in six districts',
             'summary'=>'Schools across Lahore have been closed for a fourth consecutive week as winter smog readings exceed WHO safe limits by 40 times.',
             'body'=>'<p>Schools across Lahore and five surrounding districts remain closed as the city\'s annual winter smog crisis intensifies, with air quality readings hitting levels 40 times above World Health Organisation safety thresholds.</p><p>The Punjab government declared an environmental emergency, banning construction activity and ordering brick kilns to halt production. Hospitals across the city report a 60 per cent surge in respiratory cases, with paediatric wards particularly overwhelmed.</p>',
             'cat'=>'pakistan','author'=>'tariq@cni.co.uk','tags'=>['Lahore','Climate Change','Health'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>3211,'d'=>3],

            ['title'=>'Pakistan General Elections 2026 Campaign Season Opens Across All Four Provinces',
             'subtitle'=>'PTI PML-N and PPP all launch rallies as nomination deadline passes',
             'summary'=>'Pakistan\'s three major political parties have officially launched their election campaigns ahead of the 2026 general elections scheduled for February.',
             'body'=>'<p>Pakistan\'s election season has begun in earnest, with rallies announced across all four provinces ahead of the 2026 general elections scheduled for 14 February.</p><p>Pakistan Muslim League-Nawaz held its first major rally at Minar-e-Pakistan in Lahore, drawing a crowd estimated at 50,000. Pakistan Peoples Party kicked off in Karachi, where Bilawal Bhutto Zardari promised sweeping rural development if elected.</p><p>Pakistan Tehreek-e-Insaf is running under the banner of "Khan\'s mandate" — arguing the last election result was stolen and demanding fresh elections under independent oversight.</p>',
             'cat'=>'pakistan','author'=>'tariq@cni.co.uk','tags'=>['General Elections','Imran Khan','Lahore'],
             'type'=>'news','b'=>false,'f'=>true,'v'=>6780,'d'=>2],

            ['title'=>'Karachi Faces Worst Water Crisis in a Decade as Population Surges Past 22 Million',
             'subtitle'=>'City administration warns of rationing as reservoirs fall to 40 percent capacity',
             'summary'=>'Karachi is facing its most severe water shortage in over a decade with reservoirs at 40 percent capacity.',
             'body'=>'<p>Karachi, Pakistan\'s commercial capital, is grappling with a water crisis that authorities describe as the worst in living memory, with city reservoirs running at just 40 per cent of capacity.</p><p>The Karachi Water and Sewerage Corporation has warned that without urgent investment estimated at $2 billion, the city faces catastrophic supply failures within three years. Residents in working-class neighbourhoods reported going without municipal water for up to two weeks at a time.</p>',
             'cat'=>'pakistan','author'=>'tariq@cni.co.uk','tags'=>['Karachi','Health','Economy'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>2190,'d'=>6],

            ['title'=>'UN Human Rights Council Calls for Independent Investigation into Kashmir',
             'subtitle'=>'Resolution passes 23 to 18 despite strong opposition from New Delhi',
             'summary'=>'The United Nations Human Rights Council has passed a resolution calling for an independent international investigation into reported human rights violations in Kashmir.',
             'body'=>'<p>The United Nations Human Rights Council voted 23-18 to call for an independent international investigation into reported human rights violations in Jammu and Kashmir, in a resolution that drew sharp criticism from New Delhi.</p><p>The resolution calls for a fact-finding mission with unfettered access to both Indian-administered and Pakistan-administered Kashmir. "This is a historic step," said Tariq Mir, chairman of the Kashmir Council UK. "For decades, the world has looked away. Now the international community is finally paying attention."</p>',
             'cat'=>'kashmir','author'=>'tariq@cni.co.uk','tags'=>['UN','Human Rights','Line of Control'],
             'type'=>'news','b'=>false,'f'=>true,'v'=>6103,'d'=>2],

            ['title'=>'AJK Elections Voter Turnout Exceeds 65 Percent Despite Security Concerns',
             'subtitle'=>'PTI claims early lead in Azad Kashmir legislative assembly polls',
             'summary'=>'Voters in Azad Jammu and Kashmir turned out in large numbers for legislative assembly elections with preliminary results pointing to a strong opposition showing.',
             'body'=>'<p>Voters across Azad Jammu and Kashmir cast their ballots in legislative assembly elections that recorded a turnout of over 65 per cent — one of the highest in the region\'s recent electoral history — despite intermittent security concerns that led to additional deployment of paramilitary forces.</p><p>Preliminary results show Pakistan Tehreek-e-Insaf candidates leading in 18 of the 33 general seats. Election observers noted that polling was largely peaceful but flagged concerns about equal media access for all parties.</p>',
             'cat'=>'kashmir','author'=>'tariq@cni.co.uk','tags'=>['AJK','Imran Khan','General Elections'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>2897,'d'=>5],

            ['title'=>'Line of Control Troops Exchange Fire Near Neelum Valley in Most Serious 2026 Incident',
             'subtitle'=>'Pakistan and India both report military casualties in frontier clash',
             'summary'=>'Tension along the Line of Control has escalated following an exchange of fire near Neelum Valley with both Pakistan and India reporting military casualties.',
             'body'=>'<p>Tension along the Line of Control has escalated following an exchange of fire near the Neelum Valley sector — the most serious LoC incident of 2026.</p><p>Pakistan\'s ISPR said three soldiers were killed when Indian forces opened "unprovoked fire." India denied initiating fire, saying its troops had responded to Pakistani transgression. The United Nations called on both sides to exercise maximum restraint.</p>',
             'cat'=>'kashmir','author'=>'tariq@cni.co.uk','tags'=>['Line of Control','AJK','UN'],
             'type'=>'news','b'=>true,'f'=>true,'v'=>11204,'d'=>0],

            ['title'=>'Gaza Ceasefire Talks Resume in Cairo as Aid Groups Warn of Humanitarian Catastrophe',
             'subtitle'=>'Qatari and Egyptian mediators present phased proposal to both sides',
             'summary'=>'Renewed ceasefire negotiations are underway in Cairo with international mediators presenting a phased proposal involving hostage releases and sustained humanitarian aid.',
             'body'=>'<p>High-level ceasefire negotiations between Israel and Hamas resumed in Cairo, with Qatari and Egyptian mediators presenting a phased proposal to both sides.</p><p>The proposal outlines a 42-day pause in fighting, during which hostages would be released in exchange for Palestinian prisoners. Humanitarian agencies warn the situation in Gaza has reached catastrophic proportions, with virtually the entire population of 2.2 million facing acute food insecurity.</p><p>British Muslims, including the UK\'s large Pakistani and Kashmiri communities, have maintained significant pressure on the government to take a stronger stance, with weekly protests in London, Birmingham, and Manchester drawing tens of thousands of participants.</p>',
             'cat'=>'world','author'=>'sarah@cni.co.uk','tags'=>['UN','Human Rights'],
             'type'=>'news','b'=>false,'f'=>true,'v'=>9341,'d'=>1],

            ['title'=>'Pakistan Sends Emergency Relief Team to Earthquake Hit Turkey',
             'subtitle'=>'C-130 carrying rescue workers and 12 tonnes of supplies lands in Ankara',
             'summary'=>'Pakistan has dispatched an emergency rescue and medical team to earthquake-hit Turkey with a military transport aircraft carrying 45 specialists and relief supplies.',
             'body'=>'<p>Pakistan has dispatched an emergency rescue and medical team to Turkey following a devastating earthquake that struck the Hatay and Kahramanmaras provinces, killing over 3,000 people.</p><p>A Pakistan Air Force C-130 carrying 45 rescue specialists and 12 tonnes of supplies landed at Ankara\'s Esenboga Airport. Prime Minister Shehbaz Sharif expressed solidarity with Turkey, noting the deep historical ties between the two nations.</p>',
             'cat'=>'world','author'=>'sarah@cni.co.uk','tags'=>['UN','Health'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>3892,'d'=>4],

            ['title'=>'British Pakistani Community Breaks Fundraising Record for Pakistan Flood Relief',
             'subtitle'=>'4.2 million pounds raised in 72 hours through mosque networks and social media',
             'summary'=>'The British Pakistani community has raised over 4.2 million pounds in just 72 hours for victims of catastrophic flooding in Sindh and Balochistan.',
             'body'=>'<p>The British Pakistani community has demonstrated extraordinary generosity, raising over 4.2 million pounds within 72 hours through mosque collections, social media campaigns, and community fundraisers.</p><p>Mosques across Birmingham, Bradford, Manchester, and London served as collection hubs, with some raising over 100,000 pounds in a single Friday prayers session. Social media challenges on TikTok and Instagram amplified the reach significantly.</p>',
             'cat'=>'overseas','author'=>'aisha@cni.co.uk','tags'=>['Birmingham','Manchester','London','Economy','Diaspora'],
             'type'=>'news','b'=>false,'f'=>true,'v'=>7122,'d'=>2],

            ['title'=>'Home Office Raises Family Visa Income Threshold to 38700 Pounds',
             'subtitle'=>'Advocacy groups respond with alarm as change takes effect in April',
             'summary'=>'The Home Office has confirmed that the minimum income threshold for family visas will rise to 38700 pounds, a change campaigners warn will separate thousands of British Pakistani and Kashmiri families.',
             'body'=>'<p>The Home Office has confirmed that the minimum income threshold required to sponsor a family member\'s visa will rise to 38,700 pounds — a change that campaigners warn will devastate British Pakistani and Kashmiri families who rely on the family reunion route.</p><p>Campaign group Reunite Families UK described the change as "an attack on family life" and announced a legal challenge arguing the policy is racially discriminatory. "A community pharmacist in Birmingham, a nursery nurse in Bradford, a factory worker in Manchester — people who are the backbone of this country — will not be able to bring their spouse home," said the group\'s director.</p>',
             'cat'=>'uk','author'=>'aisha@cni.co.uk','tags'=>['Birmingham','Manchester','Visa','Immigration'],
             'type'=>'analysis','b'=>false,'f'=>false,'v'=>5672,'d'=>4],

            ['title'=>'Bradford Named UK Most Diverse City for Third Consecutive Year',
             'subtitle'=>'New census data shows 52 percent of Bradford residents identify as South Asian heritage',
             'summary'=>'Bradford has been named the UK\'s most ethnically diverse city for the third year running with South Asian heritage residents now making up over half the city\'s population.',
             'body'=>'<p>Bradford has retained its title as the UK\'s most ethnically diverse city for the third consecutive year, with new ONS data revealing that residents of South Asian heritage now account for 52 per cent of the city\'s population of 580,000.</p><p>The city, often called the "capital of British Pakistan," has seen significant growth in its Kashmiri community. Community leaders welcomed the data as evidence of a thriving, confident multicultural city.</p>',
             'cat'=>'uk','author'=>'aisha@cni.co.uk','tags'=>['Bradford','Diaspora','Education'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>4103,'d'=>8],

            ['title'=>'Pakistan Crush India by 6 Wickets in Champions Trophy Final',
             'subtitle'=>'Babar Azam unbeaten 118 seals historic win at Lahore Gaddafi Stadium',
             'summary'=>'Pakistan have won the ICC Champions Trophy for the third time defeating arch-rivals India by six wickets at Lahore.',
             'body'=>'<p>Pakistan have won the ICC Champions Trophy for the third time, defeating India by six wickets in an electrifying final at Lahore\'s iconic Gaddafi Stadium.</p><p>Captain Babar Azam produced a masterclass innings of 118 not out from 127 balls as the hosts chased down India\'s target of 267 with 14 balls to spare. The result sparked jubilant scenes across Pakistan. In Bradford, Manchester, and Birmingham, British Pakistanis poured onto the streets to celebrate.</p>',
             'cat'=>'sports','author'=>'hassan@cni.co.uk','tags'=>['Cricket','Lahore'],
             'type'=>'news','b'=>true,'f'=>true,'v'=>18420,'d'=>0],

            ['title'=>'PSL 2026 Lahore Qalandars Retain Top Spot After Thrilling Super Over',
             'subtitle'=>'Fakhar Zaman heroics rescue Qalandars against Islamabad United',
             'summary'=>'Lahore Qalandars survived a dramatic Super Over against Islamabad United to retain their position at the top of the PSL 2026 points table.',
             'body'=>'<p>Lahore Qalandars survived one of the most dramatic finishes in PSL history to beat Islamabad United in a Super Over and retain their position at the top of the standings.</p><p>Fakhar Zaman produced a stunning 14 runs in the Super Over — including a six off the penultimate delivery — to leave United needing an improbable 15 to win.</p>',
             'cat'=>'sports','author'=>'hassan@cni.co.uk','tags'=>['PSL','Cricket','Lahore'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>6211,'d'=>3],

            ['title'=>'NUST Launches Urdu AI Model Trained on 50 Billion Words',
             'subtitle'=>'UrduGPT outperforms existing multilingual systems on Urdu language tasks',
             'summary'=>'Researchers at the National University of Sciences and Technology have unveiled a landmark Urdu language AI model trained on 50 billion words.',
             'body'=>'<p>Researchers at Pakistan\'s National University of Sciences and Technology (NUST) have unveiled a large language model trained specifically on Urdu text — the largest Urdu-language training corpus ever assembled at over 50 billion words.</p><p>The model, dubbed "UrduGPT," achieves state-of-the-art results on Urdu text classification, sentiment analysis, and machine translation tasks. The development is particularly relevant for CNI News, which publishes content in Urdu.</p>',
             'cat'=>'technology','author'=>'tariq@cni.co.uk','tags'=>['Islamabad','Education'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>2341,'d'=>6],

            ['title'=>'The Urdu Language Is Dying in Britain and We Are Letting It Happen',
             'subtitle'=>'A generation growing up disconnected from their ancestral tongue',
             'summary'=>'A generation of British Pakistanis is growing up unable to read or write in Urdu. This is not just a cultural loss it is a severing of the bond between diaspora and homeland.',
             'body'=>'<p>My grandmother kept a box of letters under her bed — letters from her mother in Mirpur, written in a flowing Nastaliq script that I could not read as a child and still struggle to read as an adult.</p><p>Across Britain\'s Pakistani and Kashmiri communities, a quiet generational rupture is taking place. The children of immigrants — born here, educated here, entirely at home here — are increasingly disconnected from the languages their parents and grandparents carried in their hearts across the ocean.</p><p>Language is the vessel in which culture travels. It carries poetry, humour, proverbs, ways of understanding the world that simply do not translate. When a language dies in a community, something irreplaceable goes with it.</p>',
             'cat'=>'overseas','author'=>'aisha@cni.co.uk','tags'=>['Education','Birmingham','Diaspora'],
             'type'=>'opinion','b'=>false,'f'=>false,'v'=>4102,'d'=>8],

            ['title'=>'Ramadan 2026 How British Muslims Are Redefining Community Iftar',
             'subtitle'=>'From church halls to cricket clubs — breaking fast across Britain',
             'summary'=>'This Ramadan a new generation of British Muslims is hosting community iftars in unexpected places, building bridges across communities.',
             'body'=>'<p>At St. Andrew\'s Church in Bradford\'s Manningham district, over 200 people gathered for a joint Muslim-Christian iftar hosted by the local mosque and church congregation together.</p><p>It was one of hundreds of community iftars taking place across Britain this Ramadan. In Birmingham\'s Sparkbrook, iftar pop-ups have appeared in cricket clubs, community centres and a converted Victorian library. In Manchester\'s Rusholme, restaurants are opening their doors for free community iftars three nights a week throughout the holy month.</p>',
             'cat'=>'culture','author'=>'aisha@cni.co.uk','tags'=>['Birmingham','Bradford','Manchester','Ramadan','Eid','Diaspora'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>3890,'d'=>9],

            ['title'=>'British Pakistani Entrepreneurs Raise Record 120 Million Pounds in 2025',
             'subtitle'=>'Tech fintech and health sectors lead breakthrough year for community founders',
             'summary'=>'British Pakistani entrepreneurs raised a record 120 million pounds in venture capital and private equity funding in 2025 — a 340 percent increase on 2021.',
             'body'=>'<p>British Pakistani entrepreneurs raised a record 120 million pounds in venture capital and private equity funding during 2025, according to research from the British Pakistani Chamber of Commerce — a 340 per cent increase on the 35 million raised in 2021.</p><p>Technology startups, particularly in fintech, healthtech, and AI, accounted for the majority of funding. Notable raises included Manchester-based Noor Health, a digital mental health platform for Muslim communities, which raised 18 million pounds in a Series A round. Birmingham\'s Halal Invest raised 12 million pounds.</p>',
             'cat'=>'business','author'=>'aisha@cni.co.uk','tags'=>['Economy','Birmingham','Manchester','Diaspora'],
             'type'=>'analysis','b'=>false,'f'=>true,'v'=>5430,'d'=>3],

            ['title'=>'Pakistan Football Stuns India in SAFF Qualifier to Set Up Asian Cup Showdown',
             'subtitle'=>'Captain Haider Ali nets twice as Green Shirts cause South Asian upset',
             'summary'=>'Pakistan\'s national football team defeated India 2-1 in a SAFF Championship qualifier setting up a crucial Asian Cup qualification play-off next month.',
             'body'=>'<p>Pakistan\'s national football team caused one of South Asian football\'s biggest upsets, defeating India 2-1 in a SAFF Championship qualifier in Nepal. Captain Haider Ali scored both Pakistani goals — a clinical 34th-minute finish followed by a stunning long-range strike in the 71st.</p><p>The result was celebrated by British-Pakistani football fans as a landmark moment for the sport in Pakistan.</p>',
             'cat'=>'sports','author'=>'hassan@cni.co.uk','tags'=>['Birmingham','Bradford'],
             'type'=>'news','b'=>false,'f'=>false,'v'=>4320,'d'=>5],
        ];

        foreach ($articles as $data) {
            $slug = Str::slug($data['title']);
            if (DB::table('articles')->where('slug',$slug)->exists()) continue;

            $base = $slug; $i = 1;
            while (DB::table('articles')->where('slug',$slug)->exists())
                $slug = $base.'-'.$i++;

            $authorId = $this->userIds[$data['author']]
                ?? DB::table('users')->where('email',$data['author'])->value('id')
                ?? $this->userIds['tariq@cni.co.uk'] ?? 1;

            $catId = $this->categoryIds[$data['cat']]
                ?? DB::table('categories')->where('slug',$data['cat'])->where('channel_id',$this->channelId)->value('id');
            if (!$catId) { $this->command->warn("  Skip '{$data['title']}' — category '{$data['cat']}' not found"); continue; }

            $articleId = DB::table('articles')->insertGetId([
                'channel_id'=>$this->channelId,'primary_language_id'=>$this->enLangId,
                'slug'=>$slug,'status'=>'published','type'=>$data['type'],
                'author_user_id'=>$authorId,'main_category_id'=>$catId,
                'is_breaking'=>$data['b'],'is_featured'=>$data['f'],
                'allow_comments'=>true,'view_count'=>$data['v'],
                'word_count'=>str_word_count(strip_tags($data['body'])),
                'published_at'=>now()->subDays($data['d']),
                'created_at'=>now()->subDays($data['d']+1),
                'updated_at'=>now()->subDays($data['d']),
            ]);

            DB::table('article_translations')->insert([
                'article_id'=>$articleId,'language_id'=>$this->enLangId,
                'title'=>$data['title'],'subtitle'=>$data['subtitle']?:null,
                'summary'=>$data['summary'],'body'=>$data['body'],
                'seo_title'=>$data['title'],'seo_description'=>$data['summary'],
                'created_at'=>now(),'updated_at'=>now(),
            ]);

            // FIX B: article_versions has NO updated_at column (only created_at as useCurrent)
            DB::table('article_versions')->insert([
                'article_id'=>$articleId,'language_id'=>$this->enLangId,
                'version_number'=>1,'title'=>$data['title'],'body'=>$data['body'],
                'saved_by_user_id'=>$authorId,'change_summary'=>'Initial draft',
                'created_at'=>now()->subDays($data['d']+1),
                // NO updated_at - table doesn't have this column
            ]);

            foreach ($data['tags'] as $tagName) {
                $tagSlug = Str::slug($tagName);
                $tagId = $this->tagIds[$tagSlug] ?? DB::table('tags')->where('slug',$tagSlug)->value('id');
                if ($tagId) DB::table('article_tag_map')->insertOrIgnore(['article_id'=>$articleId,'tag_id'=>$tagId]);
            }
        }
    }

    private function seedMemberships(): void
    {
        $plans = DB::table('membership_plans')->get()->keyBy('slug');
        if ($plans->isEmpty()) return;
        foreach ([['member1@example.com','free',false],['member2@example.com','gold',true],['platinum@example.com','platinum',true]] as [$email,$slug,$stripe]) {
            $uid = DB::table('users')->where('email',$email)->value('id');
            if (!$uid || !$plans->has($slug)) continue;
            if (DB::table('memberships')->where('user_id',$uid)->exists()) continue;
            $row = ['user_id'=>$uid,'membership_plan_id'=>$plans[$slug]->id,'status'=>'active',
                    'start_date'=>now()->subDays(rand(10,90))->toDateString(),
                    'end_date'=>$plans[$slug]->billing_cycle==='lifetime' ? null : now()->addMonth()->toDateString(),
                    'auto_renew'=>$stripe,'created_at'=>now()->subDays(rand(10,90)),'updated_at'=>now()];
            if ($stripe) $row['stripe_subscription_id']='sub_demo_'.$slug.'_'.Str::random(8);
            DB::table('memberships')->insert($row);
        }
    }

    // FIX C: individual inserts — each row has different columns so batch fails
    private function seedLiveStreams(): void
    {
        if (DB::table('live_streams')->where('channel_id',$this->channelId)->exists()) return;

        DB::table('live_streams')->insert([
            'channel_id'         => $this->channelId,
            'primary_platform'   => 'youtube',
            'platform_stream_id' => 'dQw4w9WgXcQ',
            'title'              => 'LIVE: Champions Trophy Victory Parade — Lahore',
            'description'        => 'Watch live as Pakistan celebrates their Champions Trophy victory. Live commentary, interviews, and crowd reactions.',
            'status'             => 'live',
            'is_public'          => true,
            'actual_start_at'    => now()->subHours(2),
            'peak_viewers'       => 24500,
            'created_at'         => now()->subHours(3),
            'updated_at'         => now(),
        ]);

        DB::table('live_streams')->insert([
            'channel_id'          => $this->channelId,
            'primary_platform'    => 'youtube',
            'title'               => 'Special Report: Pakistan Economy Review — Live Panel',
            'description'         => 'Live discussion on Pakistan\'s economic outlook with economists from Karachi and Lahore.',
            'status'              => 'scheduled',
            'is_public'           => true,
            'scheduled_start_at'  => now()->addDays(2)->setTime(19, 0),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        DB::table('live_streams')->insert([
            'channel_id'         => $this->channelId,
            'primary_platform'   => 'facebook',
            'title'              => 'Kashmir Solidarity Week — Live Community Forum',
            'description'        => 'Community leaders from Bradford, Birmingham and Manchester discuss latest Kashmir developments.',
            'status'             => 'scheduled',
            'is_public'          => true,
            'scheduled_start_at' => now()->addDays(4)->setTime(20, 30),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function seedEvents(): void
    {
        if (DB::table('events')->where('channel_id',$this->channelId)->exists()) return;

        $comId = DB::table('event_types')->where('name','Community')->value('id');
        $culId = DB::table('event_types')->where('name','Cultural')->value('id');
        $eid   = DB::table('users')->where('email','editor@cni.co.uk')->value('id') ?? 1;

        $events = [
            ['event_type_id'=>$comId,'title'=>'CNI News Annual Community Awards 2026',
             'description'=>'5th Annual CNI Community Awards. Black tie dinner celebrating outstanding British Pakistanis and Kashmiris across business, arts, sport, and community service.',
             'location_name'=>'Grand Connaught Rooms','address'=>'61-65 Great Queen Street',
             'city'=>'London','country'=>'GB',
             'starts_at'=>now()->addDays(30)->setTime(18,30),
             'ends_at'=>now()->addDays(30)->setTime(23,0),
             'ticket_price'=>75.00,'max_capacity'=>300],
            ['event_type_id'=>$culId,'title'=>'Eid ul-Fitr Family Festival Bradford',
             'description'=>'Bradford\'s biggest Eid festival. Free entry. Live nasheeds, food stalls, children\'s activities.',
             'location_name'=>'Centenary Square','address'=>'Centenary Square',
             'city'=>'Bradford','country'=>'GB',
             'starts_at'=>now()->addDays(45)->setTime(10,0),
             'ends_at'=>now()->addDays(45)->setTime(18,0),
             'ticket_price'=>0.00,'max_capacity'=>null],
            ['event_type_id'=>$comId,'title'=>'Kashmir Conference 2026 Manchester',
             'description'=>'Annual Kashmir Conference with community leaders, academics, politicians and journalists. Free to attend.',
             'location_name'=>'Manchester Central Convention Complex',
             'address'=>'Manchester Central, Windmill Street',
             'city'=>'Manchester','country'=>'GB',
             'starts_at'=>now()->addDays(60)->setTime(9,0),
             'ends_at'=>now()->addDays(60)->setTime(17,0),
             'ticket_price'=>0.00,'max_capacity'=>800],
            ['event_type_id'=>$culId,'title'=>'Birmingham Mela 2026',
             'description'=>'Europe\'s largest South Asian arts festival. Three days of music, food, culture and community celebration.',
             'location_name'=>'Cannon Hill Park','address'=>'Pershore Road, Edgbaston',
             'city'=>'Birmingham','country'=>'GB',
             'starts_at'=>now()->addDays(75)->setTime(11,0),
             'ends_at'=>now()->addDays(77)->setTime(20,0),
             'ticket_price'=>0.00,'max_capacity'=>null],
        ];

        foreach ($events as $event) {
            DB::table('events')->insert([
                'channel_id'        => $this->channelId,
                'event_type_id'     => $event['event_type_id'],
                'organizer_user_id' => $eid,
                'title'             => $event['title'],
                'description'       => $event['description'],
                'location_name'     => $event['location_name'],
                'address'           => $event['address'],
                'city'              => $event['city'],
                'country'           => $event['country'],
                'starts_at'         => $event['starts_at'],
                'ends_at'           => $event['ends_at'],
                'is_public'         => true,
                'status'            => 'published',
                'ticket_price'      => $event['ticket_price'],
                'max_capacity'      => $event['max_capacity'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    // FIX D: individual inserts — NULL columns in different rows breaks batch insert
    private function seedPromoCodes(): void
    {
        if (DB::table('promo_codes')->where('code','RAMADAN25')->exists()) return;

        $goldId     = DB::table('membership_plans')->where('slug','gold')->value('id');
        $platinumId = DB::table('membership_plans')->where('slug','platinum')->value('id');
        $creatorId  = DB::table('users')->where('email', 'editor@cni.co.uk')->value('id') ?? 1;

        DB::table('promo_codes')->insert([
            'channel_id'         => $this->channelId,
            'created_by_user_id' => $creatorId,
            'code'               => 'RAMADAN25',
            'description'        => 'Ramadan 2026 — 25% off Gold membership',
            'discount_type'      => 'percentage',
            'discount_value'     => 25,
            'applicable_plan_id' => $goldId,
            'max_uses'           => 500,
            'max_uses_per_user'  => 1,
            'uses_count'         => 47,
            'valid_from'         => now()->subDays(10)->toDateString(),
            'valid_until'        => now()->addDays(20)->toDateString(),
            'is_active'          => true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('promo_codes')->insert([
            'channel_id'         => $this->channelId,
            'created_by_user_id' => $creatorId,
            'code'               => 'WELCOME10',
            'description'        => 'Welcome discount — 1 pound off any paid plan',
            'discount_type'      => 'fixed_amount',
            'discount_value'     => 1.00,
            'applicable_plan_id' => null,
            'max_uses'           => null,
            'max_uses_per_user'  => 1,
            'uses_count'         => 23,
            'valid_from'         => null,
            'valid_until'        => null,
            'is_active'          => true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        DB::table('promo_codes')->insert([
            'channel_id'         => $this->channelId,
            'created_by_user_id' => $creatorId,
            'code'               => 'PLATINUM50',
            'description'        => 'Launch offer — 50% off first month Platinum',
            'discount_type'      => 'percentage',
            'discount_value'     => 50,
            'applicable_plan_id' => $platinumId,
            'max_uses'           => 100,
            'max_uses_per_user'  => 1,
            'uses_count'         => 12,
            'valid_from'         => now()->subDays(30)->toDateString(),
            'valid_until'        => now()->addDays(30)->toDateString(),
            'is_active'          => true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }



    private function seedComments(): void
    {
        if (DB::table('comments')->exists()) return;
        $allComments = [
            ['name'=>'Khalid Hussain', 'content'=>'Excellent reporting as always from CNI News. This is exactly the journalism our community needs.','status'=>'approved'],
            ['name'=>'Nasreen Akhtar', 'content'=>'Thank you for keeping us informed. I shared this with my whole family in Birmingham.','status'=>'approved'],
            ['name'=>'Abdul Rehman',   'content'=>'Very biased reporting. Where is the other side of the story?','status'=>'pending'],
            ['name'=>'Rizwana Begum',  'content'=>'MashaAllah, so proud of our community. Please keep up this important work.','status'=>'approved'],
            ['name'=>'Tariq Shah',     'content'=>'Finally some proper journalism. I have been following CNI for years and the quality keeps improving.','status'=>'approved'],
            ['name'=>'Amina Bibi',     'content'=>'This is so important. People in the UK have no idea what is happening back home.','status'=>'approved'],
            ['name'=>'Mohammed Rafiq', 'content'=>'Please keep covering these stories. The mainstream media ignores us completely.','status'=>'approved'],
            ['name'=>'Sajida Parveen', 'content'=>'I have been waiting for someone to write about this properly. Well done CNI.','status'=>'approved'],
        ];
        $articles = DB::table('articles')->take(2)->get();
        foreach ($articles as $idx => $article) {
            foreach (array_slice($allComments, $idx * 4, 4) as $c) {
                DB::table('comments')->insert([
                    'article_id'=>$article->id,'guest_name'=>$c['name'],
                    'content'=>$c['content'],'status'=>$c['status'],
                    'created_at'=>now()->subHours(rand(1,72)),'updated_at'=>now(),
                ]);
            }
        }
    }
}
