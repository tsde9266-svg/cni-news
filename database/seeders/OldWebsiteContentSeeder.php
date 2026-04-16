<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * CNI Old Website Content Seeder
 *
 * Seeds all articles, events, and categories scraped from the old cninews.tv WordPress site.
 * Images reference the original CDN URLs (wp-content/uploads) stored in media_assets.original_url.
 *
 * Run: php artisan db:seed --class=OldWebsiteContentSeeder
 * Safe to re-run: all inserts check for existence first.
 */
class OldWebsiteContentSeeder extends Seeder
{
    private int   $channelId;
    private int   $enLangId;
    private int   $authorId;
    private array $categoryIds = [];

    public function run(): void
    {
        $this->channelId = DB::table('channels')->where('slug', 'cni-news')->value('id') ?? 1;
        $this->enLangId  = DB::table('languages')->where('code', 'en')->value('id') ?? 1;

        $this->command->info('🌱 Seeding old cninews.tv content...');

        $this->ensureCategories();
        $this->ensureAuthor();
        $this->seedArticles();
        $this->seedEvents();

        $this->command->info('✅ Old website content seeded!');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Categories
    // ─────────────────────────────────────────────────────────────────────────
    private function ensureCategories(): void
    {
        $cats = [
            ['slug' => 'pakistan',  'default_name' => 'Pakistan',  'position' => 1],
            ['slug' => 'kashmir',   'default_name' => 'Kashmir',   'position' => 2],
            ['slug' => 'world',     'default_name' => 'World',     'position' => 3],
            ['slug' => 'overseas',  'default_name' => 'Overseas',  'position' => 4],
            ['slug' => 'articles',  'default_name' => 'Articles',  'position' => 5],
            ['slug' => 'sports',    'default_name' => 'Sports',    'position' => 6],
            ['slug' => 'culture',   'default_name' => 'Culture',   'position' => 7],
            ['slug' => 'videos',    'default_name' => 'Videos',    'position' => 8],
            ['slug' => 'events',    'default_name' => 'Events',    'position' => 9],
        ];

        foreach ($cats as $cat) {
            $existing = DB::table('categories')
                ->where('channel_id', $this->channelId)
                ->where('slug', $cat['slug'])
                ->first();

            if ($existing) {
                $this->categoryIds[$cat['slug']] = $existing->id;
            } else {
                $id = DB::table('categories')->insertGetId([
                    'channel_id'   => $this->channelId,
                    'slug'         => $cat['slug'],
                    'default_name' => $cat['default_name'],
                    'position'     => $cat['position'],
                    'parent_id'    => null,
                    'is_featured'  => false,
                    'is_active'    => true,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                $this->categoryIds[$cat['slug']] = $id;
                $this->command->line("  Created category: {$cat['default_name']}");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Author — Syed Abid Kazmi
    // ─────────────────────────────────────────────────────────────────────────
    private function ensureAuthor(): void
    {
        $existing = DB::table('users')->where('email', 'abid.kazmi@cninews.tv')->first();

        if ($existing) {
            $this->authorId = $existing->id;
            $this->command->line('  Author already exists: Syed Abid Kazmi');
            return;
        }

        $this->authorId = DB::table('users')->insertGetId([
            'name'              => 'Syed Abid Kazmi',
            'display_name'      => 'Syed Abid Kazmi',
            'first_name'        => 'Syed Abid',
            'last_name'         => 'Kazmi',
            'email'             => 'abid.kazmi@cninews.tv',
            'password_hash'     => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
            'is_email_verified' => true,
            'channel_id'        => $this->channelId,
            'status'            => 'active',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Create author profile
        if (DB::getSchemaBuilder()->hasTable('author_profiles')) {
            $profileExists = DB::table('author_profiles')->where('user_id', $this->authorId)->exists();
            if (! $profileExists) {
                DB::table('author_profiles')->insert([
                    'user_id'          => $this->authorId,
                    'pen_name'         => 'Syed Abid Kazmi',
                    'byline'           => 'Journalist and Correspondent',
                    'bio'              => 'Journalist and correspondent at CNI News Network. Covering Pakistan, Kashmir, UK and world affairs.',
                    'can_self_publish' => true,
                    'is_monetised'     => false,
                    'is_active'        => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }

        $this->command->line('  Created author: Syed Abid Kazmi');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Articles
    // ─────────────────────────────────────────────────────────────────────────
    private function seedArticles(): void
    {
        foreach ($this->getArticles() as $data) {
            $slug = $data['slug'];

            $exists = DB::table('articles')
                ->where('channel_id', $this->channelId)
                ->where('slug', $slug)
                ->exists();

            if ($exists) {
                $this->command->line("  Skipped (exists): {$data['title']}");
                continue;
            }

            // Create media_asset for the featured image (stores external URL)
            $mediaId = null;
            if (! empty($data['image'])) {
                $mediaId = $this->insertMediaAsset($data['image'], $data['title']);
            }

            // Primary category
            $primaryCatSlug = $data['categories'][0] ?? null;
            $mainCategoryId = $primaryCatSlug ? ($this->categoryIds[$primaryCatSlug] ?? null) : null;

            $articleId = DB::table('articles')->insertGetId([
                'channel_id'              => $this->channelId,
                'primary_language_id'     => $this->enLangId,
                'author_user_id'          => $this->authorId,
                'slug'                    => $slug,
                'status'                  => 'published',
                'type'                    => 'news',
                'is_breaking'             => $data['is_breaking'] ?? false,
                'is_featured'             => $data['is_featured'] ?? false,
                'allow_comments'          => true,
                'featured_image_media_id' => $mediaId,
                'main_category_id'        => $mainCategoryId,
                'view_count'              => 0,
                'published_at'            => $data['published_at'],
                'created_at'              => $data['published_at'],
                'updated_at'              => $data['updated_at'] ?? $data['published_at'],
            ]);

            // Article translation (English)
            DB::table('article_translations')->insert([
                'article_id'  => $articleId,
                'language_id' => $this->enLangId,
                'title'       => $data['title'],
                'body'        => $data['body'],
                'summary'     => $data['excerpt'] ?? Str::limit(strip_tags($data['body']), 200),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            // Category pivot (article_category_map)
            foreach ($data['categories'] as $i => $catSlug) {
                $catId = $this->categoryIds[$catSlug] ?? null;
                if ($catId) {
                    DB::table('article_category_map')->insertOrIgnore([
                        'article_id'  => $articleId,
                        'category_id' => $catId,
                        'is_primary'  => $i === 0 ? 1 : 0,
                    ]);
                }
            }

            $this->command->line("  Created article: {$data['title']}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Insert media_asset record for an external image URL
    // ─────────────────────────────────────────────────────────────────────────
    private function insertMediaAsset(string $url, string $title): int
    {
        // Reuse if already seeded
        $existing = DB::table('media_assets')
            ->where('original_url', $url)
            ->value('id');

        if ($existing) return $existing;

        return DB::table('media_assets')->insertGetId([
            'owner_user_id'    => $this->authorId,
            'channel_id'       => $this->channelId,
            'type'             => 'image',
            'storage_provider' => 'local',
            'disk'             => 'local',
            'original_url'     => $url,
            'title'            => $title,
            'alt_text'         => $title,
            'uploaded_at'      => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Events
    // ─────────────────────────────────────────────────────────────────────────
    private function seedEvents(): void
    {
        foreach ($this->getEvents() as $data) {
            $exists = DB::table('events')
                ->where('channel_id', $this->channelId)
                ->where('title', $data['title'])
                ->exists();

            if ($exists) {
                $this->command->line("  Skipped event (exists): {$data['title']}");
                continue;
            }

            DB::table('events')->insert([
                'channel_id'    => $this->channelId,
                'title'         => $data['title'],
                'description'   => $data['description'],
                'location_name' => $data['location_name'],
                'city'          => $data['city'],
                'country'       => $data['country'] ?? 'GB',
                'starts_at'     => $data['starts_at'],
                'ends_at'       => $data['ends_at'] ?? null,
                'status'        => 'published',
                'is_public'     => true,
                'ticket_price'  => $data['ticket_price'] ?? 0,
                'max_capacity'  => $data['max_capacity'] ?? null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $this->command->line("  Created event: {$data['title']}");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Article Data — scraped from cninews.tv
    // ─────────────────────────────────────────────────────────────────────────
    private function getArticles(): array
    {
        return [

            // ── PAKISTAN ──────────────────────────────────────────────────────

            [
                'slug'         => 'pm-shehbaz-embarks-on-regional-outreach-tour',
                'title'        => 'PM Shehbaz Embarks on Regional Outreach Tour, Begins First Leg in Turkiye',
                'categories'   => ['pakistan'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/Screenshot_25-5-2025_112229_www.dawn_.com_-1.jpeg',
                'published_at' => '2025-05-25 09:22:00',
                'is_featured'  => true,
                'excerpt'      => 'Prime Minister Shehbaz Sharif departed for Turkiye on Sunday afternoon as he embarked on the first leg of his four-nation tour aimed at strengthening ties with friendly countries.',
                'body'         => '<p>Prime Minister Shehbaz Sharif departed for Turkiye on Sunday afternoon as he embarked on the first leg of his four-nation tour aimed at strengthening ties with friendly countries. The premier will visit Turkiye, Iran, Azerbaijan and Tajikistan from May 25-30, according to the Foreign Office. The tour follows diplomatic support voiced by three of these countries for Pakistan during the recent military confrontation with India.</p>

<p>The prime minister is accompanied by Deputy PM and Foreign Minister Ishaq Dar, Information Minister Attaullah Tarar, and Special Assistant to PM Tariq Fatemi.</p>

<p>During the visit, PM Shehbaz will engage in "wide-ranging discussions with the leaders of these countries on an entire range of issues covering bilateral relations and matters of regional and international importance." The premier will also express appreciation for support extended during the recent crisis with India.</p>

<p>While in Tajikistan, the premier will attend the International Conference on Glaciers in Dushanbe on May 29-30. As tensions escalated between Islamabad and New Delhi following Indian strikes on May 6, Turkish President Recep Tayyip Erdogan conveyed solidarity and supported Pakistan\'s "calm and restrained policies." Azerbaijan\'s President Ilham Aliyev congratulated PM Shehbaz on Pakistan\'s success, and Iran offered mediation efforts.</p>

<p>The diplomatic support from Turkiye and Azerbaijan provoked Indian backlash, including cancelled holidays and boycotts of Turkish products.</p>',
            ],

            [
                'slug'         => 'pakistan-reiterates-call-for-dialogue-with-india',
                'title'        => 'Pakistan reiterates call for dialogue with India to resolve outstanding issues',
                'categories'   => ['pakistan'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/azarbaijan-2.webp',
                'published_at' => '2025-05-28 19:16:00',
                'excerpt'      => 'BAKU: Prime Minister Shehbaz Sharif on Wednesday reiterated Pakistan\'s call for dialogue with India to resolve outstanding issues including Kashmir.',
                'body'         => '<p>Prime Minister Shehbaz Sharif addressed a Pakistan-Turkiye-Azerbaijan Trilateral Summit in Baku, emphasizing that "We must sit together and talk for the sake of peace… There are issues that demand immediate attention and must be addressed through dialogue."</p>

<p>The PM highlighted three critical areas requiring negotiation: Kashmir, water resources, and counterterrorism. He reiterated Pakistan\'s commitment to peace, stating the nation "desired peace yesterday" and will "continue to desire peace in the future."</p>

<p>Regarding Kashmir, Sharif asserted Pakistan seeks resolution aligned with UN Security Council resolutions and Kashmiri aspirations. He accused India of attempting to weaponize the Indus Waters Treaty, warning: "It is most unfortunate that India tried to threaten to stop the flow of water into Pakistan. This is never possible."</p>

<p>The PM noted Pakistan\'s significant terrorism losses — 90,000 lives and $150 billion in economic damages — demonstrating commitment to combating the threat.</p>

<p>Sharif credited Field Marshal Syed Asim Munir\'s leadership during the recent military standoff triggered by Indian missile strikes on May 7, which resulted in 31 civilian deaths. Pakistan responded by downing six fighter jets and numerous drones. A ceasefire was agreed upon May 10 after four days of intense cross-border strikes.</p>

<p>Azerbaijan\'s President Ilham Aliyev announced $2 billion investment plans for Pakistan. Turkey\'s President Recep Tayyip Erdogan praised the trilateral relationship and Sharif\'s diplomatic approach during the conflict.</p>',
            ],

            [
                'slug'         => 'junaid-safdar-set-to-get-engaged-within-sharif-family-circle',
                'title'        => 'Junaid Safdar set to get \'engaged within Sharif family circle\'',
                'categories'   => ['pakistan', 'articles'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/junaid-safdar-1.jpeg',
                'published_at' => '2025-05-20 12:00:00',
                'excerpt'      => 'LONDON: Punjab Chief Minister Maryam Nawaz Sharif\'s son, Junaid Safdar, will become engaged in Lahore to the granddaughter of one of PML-N President Nawaz Sharif\'s first cousins.',
                'body'         => '<p>Punjab Chief Minister Maryam Nawaz Sharif\'s son, Junaid Safdar, will become engaged in Lahore to the granddaughter of one of PML-N President Nawaz Sharif\'s first cousins. Three family sources informed Geo News that Junaid\'s marriage has been finalized with the daughter of Usman Javed, 48, who is the son of Javed Shafi — a first cousin of Nawaz Sharif.</p>

<p>According to sources, the families — including Nawaz, Maryam, and Javed Shafi — met Wednesday at the former\'s Model Town residence, where formalities were completed. "The rishta has been settled and the engagement will follow soon. The families will mutually decide the dates for the nikah and engagement ceremony," one source stated.</p>

<p>This represents Junaid Safdar\'s second marriage. His previous marriage to Ayesha Saif, daughter of Qatar-based businessman Saifur Rehman Khan, took place in London in 2021 at The Lanesborough in Knightsbridge. The couple divorced amicably in October 2023.</p>

<p>A Cambridge graduate, Junaid holds two bachelor\'s and two master\'s degrees from UK universities. He completed a master\'s in International Relations from the London School of Economics in 2020. Junaid is also an accomplished polo player with multiple competition wins representing British universities.</p>',
            ],

            // ── WORLD ─────────────────────────────────────────────────────────

            [
                'slug'         => 'tragic-house-fire-in-brent-claims-lives-mother-three-children',
                'title'        => 'Tragic House Fire in Brent Claims Lives of Mother and Three Children, Man Arrested for Suspected Arson',
                'categories'   => ['world'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/london-fire-1.jpeg',
                'published_at' => '2025-05-10 08:00:00',
                'is_breaking'  => true,
                'is_featured'  => true,
                'excerpt'      => 'Emergency services responded to a devastating blaze at a residential property on Tillett Close in Stonebridge, Brent.',
                'body'         => '<p>Emergency services responded to a devastating blaze at a residential property on Tillett Close in Stonebridge, Brent, at 1:22am on Saturday. A 43-year-old woman and her three children — a 15-year-old girl, an eight-year-old boy, and a four-year-old boy — were tragically pronounced dead at the scene.</p>

<p>British media have reported that a man has been arrested on suspicion of murder, as police treat the incident as a potential arson attack. Authorities have not yet released further details about the suspect, and investigations remain ongoing.</p>

<p>Two additional victims — a 70-year-old woman and a young girl — were rescued from the fire and taken to a nearby hospital with injuries. Their current medical condition has not been disclosed.</p>

<p>The victims\' next of kin have been informed. The family had reportedly moved to the UK from Pakistan nearly two decades ago. The tragedy has deeply shocked the local community, as police continue to appeal for information to assist with the investigation.</p>',
            ],

            [
                'slug'         => 'carney-unveils-new-cabinet-aimed-at-redefining-canada-us-relations',
                'title'        => 'Carney Unveils New Cabinet Aimed at Redefining Canada–US Relations',
                'categories'   => ['world', 'culture'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/news1-1.jpeg',
                'published_at' => '2025-05-07 10:00:00',
                'excerpt'      => 'Canadian Prime Minister Mark Carney has unveiled a new cabinet to reshape the relationship with the United States following his Liberal Party\'s general election victory.',
                'body'         => '<p>Canadian Prime Minister Mark Carney has unveiled a new cabinet to reshape the relationship with the United States. Following his Liberal Party\'s general election victory two weeks prior, Carney\'s cabinet was sworn in during a ceremony in Ottawa on Tuesday.</p>

<p>"Canadians elected this new government with a strong mandate to define a new economic and security relationship with the United States," stated Carney\'s office.</p>

<p>The new cabinet comprises 29 ministers, reduced from the previous 39 under Justin Trudeau. Key positions remain unchanged, including Finance Minister Francois-Philippe Champagne and Dominic LeBlanc, handling US trade matters. The cabinet combines Trudeau-era allies with fresh appointments, notably journalist Evan Solomon, named to the newly created artificial intelligence minister role.</p>

<p>Carney\'s economic agenda emphasizes trade diversification to reduce US dependence, alongside promised investments and tax reductions. US-Canada relations have deteriorated due to President Donald Trump\'s 25 percent tariffs on Canadian goods and annexation rhetoric. During his May 6 Washington visit, Carney asserted Canada "is not for sale" and "won\'t be for sale, ever."</p>',
            ],

            [
                'slug'         => 'elon-musk-disappointed-by-trump-bill-rare-break-with-president',
                'title'        => 'Billionaire Elon Musk \'disappointed\' by Trump bill, in rare break with US President',
                'categories'   => ['world'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/azarbaijan-1-1.webp',
                'published_at' => '2025-05-30 14:00:00',
                'excerpt'      => 'Elon Musk has publicly criticized Donald Trump\'s spending legislation, marking a significant departure from their previously aligned relationship.',
                'body'         => '<p>Elon Musk has publicly criticized Donald Trump\'s spending legislation, marking a significant departure from their previously aligned relationship. The tech entrepreneur expressed concerns that the "One Big, Beautiful Bill Act" — which passed the House and now awaits Senate consideration — would expand rather than reduce the budget deficit.</p>

<p>Musk stated: "I was disappointed to see the massive spending bill, frankly, which increases the budget deficit" and remarked that such legislation struggles to be both "big" and "beautiful" simultaneously.</p>

<p>The billionaire also disclosed his frustration with DOGE becoming a scapegoat for administration problems, noting that the department faced blame for issues beyond its control. After stepping back from the role in late April to focus on SpaceX and Tesla, Musk acknowledged the federal bureaucracy proved more complex than anticipated.</p>

<p>The White House downplayed disagreement over the bill, with Deputy Chief of Staff Stephen Miller clarifying that DOGE cuts would require separate legislation under Senate rules.</p>',
            ],

            [
                'slug'         => 'pakistan-and-iran-border-open-24-7-for-pilgrims',
                'title'        => 'Pakistan and Iran to Keep Border Open 24/7 for Pilgrims',
                'categories'   => ['world', 'pakistan'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/mohsin-naqvi-1.webp',
                'published_at' => '2025-05-15 11:00:00',
                'excerpt'      => 'ISLAMABAD: Pakistan and Iran have agreed to keep their border open around the clock during Muharram and Safar to facilitate religious pilgrims.',
                'body'         => '<p>Pakistan and Iran have reached an agreement to maintain round-the-clock border access during Muharram and Safar — two significant Islamic months — to facilitate religious pilgrims traveling between the nations.</p>

<p>Interior Minister Mohsin Naqvi met with his Iranian counterpart Eskandar Momeni in Tehran to finalize this arrangement. "Both countries have made several key decisions to improve pilgrim facilitation and strengthen border cooperation."</p>

<p>The accord includes Iran providing accommodation and meals in Mashhad for 5,000 Pakistani pilgrims. A direct communication channel will be established between the countries for prompt issue resolution.</p>

<p>Additional measures encompass increased flight frequencies for pilgrims and exploration of maritime transportation options. Both nations committed to enhanced cooperation addressing illegal immigration, human trafficking, and drug control alongside improved border security management.</p>

<p>The agreement follows Prime Minister Shehbaz Sharif\'s Tehran visit, during which he reaffirmed Pakistan\'s readiness for dialogue with India concerning Kashmir and regional stability.</p>',
            ],

            // ── SPORTS ────────────────────────────────────────────────────────

            [
                'slug'         => 'rizwan-lacks-leadership-qualities-kamran-akmal-psl-sultans-exit',
                'title'        => '"Rizwan Lacks Leadership Qualities," Says Kamran Akmal After Sultans\' HBL PSL Exit',
                'categories'   => ['sports'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/kamran-akmal-1.jpeg',
                'published_at' => '2025-05-18 16:00:00',
                'excerpt'      => 'Former Pakistan wicketkeeper batter Kamran Akmal has openly criticised Mohammad Rizwan\'s leadership abilities, declaring him unfit for captaincy.',
                'body'         => '<p>Former Pakistan wicketkeeper batter Kamran Akmal has openly criticized Mohammad Rizwan\'s leadership abilities, declaring him unfit for captaincy and urging the Pakistan Cricket Board (PCB) to make a decisive call on his future role. Speaking on a private sports show, Akmal launched a sharp critique of Rizwan\'s tactics and composure during the ongoing HBL Pakistan Super League (PSL) 10, particularly highlighting Multan Sultans\' crushing defeat to Peshawar Zalmi.</p>

<p>Akmal stated: "If the Pakistan Cricket Board has the courage, they should make a firm decision and never give such powers to him again. He is simply not captain material."</p>

<p>The criticism comes as Multan Sultans suffered a heavy defeat against Peshawar Zalmi in the PSL playoffs, with analysts pointing to poor bowling changes and fielding placements during crucial moments as key factors in the loss.</p>',
            ],

            // ── CULTURE / EVENTS ──────────────────────────────────────────────

            [
                'slug'         => 'join-us-for-pakistans-78th-independence-day-celebration',
                'title'        => 'Join Us for Pakistan\'s 78th Independence Day Celebration!',
                'categories'   => ['culture', 'articles'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/07/WhatsApp-Image-2025-07-30-at-13.17.26-2-860x1113.jpeg',
                'published_at' => '2025-07-30 13:00:00',
                'is_featured'  => true,
                'excerpt'      => 'Celebrate Pakistan\'s 78th Independence Day at Regent Park Banqueting Hall, Birmingham! An evening of cultural performances, food, and community celebration.',
                'body'         => '<p>CNI News Network cordially invites the community to celebrate Pakistan\'s 78th Independence Day at Regent Park Banqueting Hall, Birmingham.</p>

<h2>Event Programme</h2>
<ul>
<li><strong>6:30 PM</strong> — Doors open. Cultural performances, food, and community celebration.</li>
<li><strong>7:00 PM</strong> — Qawwali · Folk Dance · Patriotic Poetry</li>
<li><strong>7:30 PM</strong> — Authentic Pakistani cuisine and chai served</li>
<li><strong>8:30 PM</strong> — Unity and heritage celebration with children\'s activities</li>
<li><strong>10:00 PM</strong> — Family activities including games, crafts, parade, performances, and dinner</li>
</ul>

<p><strong>Date:</strong> 14 August<br>
<strong>Venue:</strong> Regent Park Banqueting Hall, Birmingham<br>
<strong>Time:</strong> 6:30 PM onwards</p>

<p>All are welcome to this free community event. Come celebrate the spirit of Pakistan with us!</p>',
            ],

            [
                'slug'         => 'pakistan-resolution-day-cultural-heritage-celebration-birmingham-2026',
                'title'        => 'Pakistan Resolution Day & Cultural Heritage Celebration – Birmingham 2026',
                'categories'   => ['culture', 'events'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2026/02/event-22-march-1024x722.png',
                'published_at' => '2026-02-18 17:26:00',
                'is_featured'  => true,
                'excerpt'      => 'Join us on Sunday, 22 March 2026 at Regent Park Hall, Birmingham — an evening of cultural performances, traditional music, and distinguished speakers.',
                'body'         => '<p>CNI News Network, in partnership with Birmingham Entertainment Group, is proud to present the Pakistan Resolution Day &amp; Cultural Heritage Celebration 2026.</p>

<p><strong>Date:</strong> Sunday, 22 March 2026<br>
<strong>Venue:</strong> Regent Park Hall, Birmingham<br>
<strong>Organiser:</strong> CNI News Network &amp; Birmingham Entertainment Group</p>

<p>This special evening commemorates the historic Lahore Resolution (23 March 1940) while celebrating the rich cultural heritage of Pakistan and the British-Pakistani community.</p>

<p>The event features:</p>
<ul>
<li>Cultural performances and traditional music</li>
<li>Distinguished guest speakers</li>
<li>Networking opportunities for community leaders and professionals</li>
<li>Traditional Pakistani cuisine</li>
</ul>

<p>The event aims to honour the historic Lahore Resolution while promoting unity, harmony, and multicultural pride across Birmingham. All members of the community are warmly invited to this free event.</p>',
            ],

            // ── KASHMIR / VIDEOS ──────────────────────────────────────────────

            [
                'slug'         => 'british-pakistani-versace-khans-shocking-kidnapping-story-interview',
                'title'        => 'British Pakistani Versace Khan\'s Shocking Kidnapping Story | Interview with Moeen Ahmed | CNI News',
                'categories'   => ['kashmir', 'videos'],
                'image'        => 'https://cninews.tv/wp-content/uploads/2025/05/versachi-1.jpeg',
                'published_at' => '2025-05-25 10:00:00',
                'excerpt'      => 'An exclusive interview with British-Pakistani Versace Khan discussing his shocking kidnapping story, conducted by interviewer Moeen Ahmed for CNI News.',
                'body'         => '<p>CNI News presents an exclusive interview featuring British-Pakistani individual Versace Khan, who shares the shocking story of his kidnapping experience with interviewer Moeen Ahmed.</p>

<p>In this candid and powerful interview, Versace Khan recounts the harrowing details of his ordeal, shedding light on the dangers faced by members of the British-Pakistani community and raising important questions about community safety and support systems.</p>

<p><strong>Watch the full interview on YouTube:</strong><br>
<a href="https://youtu.be/4H8AZEYwVqs" target="_blank" rel="noopener noreferrer">https://youtu.be/4H8AZEYwVqs</a></p>',
            ],

            // ── OVERSEAS ──────────────────────────────────────────────────────

            [
                'slug'         => 'hadiqah-kayani-in-uk-for-gaza-children-not-music-events',
                'title'        => 'Hadiqah Kayani: "I\'m in the UK to Help Gaza\'s Children, Not for Music Events"',
                'categories'   => ['overseas'],
                'image'        => null,
                'published_at' => '2026-01-15 12:00:00',
                'excerpt'      => 'Pakistani singer Hadiqah Kayani has clarified that her UK visit is focused on humanitarian work for Gaza\'s children through welfare organisation Aghosh UK.',
                'body'         => '<p>Birmingham (CNI News) — Pakistani singer and humanitarian Hadiqah Kayani has spoken out to clarify the purpose of her visit to the United Kingdom, emphasising that she is here to support the children of Gaza through welfare organisation Aghosh UK, and not to perform at music events.</p>

<p>Speaking at a special event organised by Aghosh UK, Hadiqah Kayani said: "I am not here for music events. I am here for the children of Gaza who need our help and support."</p>

<p>Aghosh UK is a registered charity based in Birmingham that works to support vulnerable children and families across the world, with a particular focus on conflict zones and developing regions.</p>

<p>Kayani\'s visit has been welcomed by members of the British-Pakistani community in Birmingham, who have praised her for using her platform to draw attention to the ongoing humanitarian crisis in Gaza.</p>',
            ],

        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Events Data — scraped from cninews.tv
    // ─────────────────────────────────────────────────────────────────────────
    private function getEvents(): array
    {
        return [
            [
                'title'         => 'Pakistan\'s 78th Independence Day Celebration',
                'description'   => "CNI News Network invites the community to celebrate Pakistan's 78th Independence Day!\n\nDate: 14 August 2025\nTime: 6:30 PM onwards\nVenue: Regent Park Banqueting Hall, Birmingham\n\nEvent Programme:\n• 6:30 PM — Doors open. Cultural performances, food, and community celebration\n• 7:00 PM — Qawwali · Folk Dance · Patriotic Poetry\n• 7:30 PM — Authentic Pakistani cuisine and chai\n• 8:30 PM — Unity and heritage celebration with children\n• 10:00 PM — Family activities including games, crafts, parade, performances, and dinner\n\nOrganised by CNI News Network. All are welcome to this free community event!",
                'location_name' => 'Regent Park Banqueting Hall',
                'city'          => 'Birmingham',
                'country'       => 'GB',
                'starts_at'     => '2025-08-14 18:30:00',
                'ends_at'       => '2025-08-14 23:00:00',
                'ticket_price'  => 0,
                'max_capacity'  => null,
            ],
            [
                'title'         => 'Pakistan Resolution Day & Cultural Heritage Celebration – Birmingham 2026',
                'description'   => "CNI News Network, in partnership with Birmingham Entertainment Group, presents the Pakistan Resolution Day & Cultural Heritage Celebration 2026.\n\nDate: Sunday, 22 March 2026\nVenue: Regent Park Hall, Birmingham\n\nThis special evening commemorates the historic Lahore Resolution (23 March 1940) while celebrating the rich cultural heritage of Pakistan and the British-Pakistani community.\n\nThe event features:\n• Cultural performances and traditional music\n• Distinguished guest speakers\n• Networking opportunities for community leaders and professionals\n• Traditional Pakistani cuisine\n\nAll members of the community are warmly invited to this free event.",
                'location_name' => 'Regent Park Hall',
                'city'          => 'Birmingham',
                'country'       => 'GB',
                'starts_at'     => '2026-03-22 18:00:00',
                'ends_at'       => '2026-03-22 23:00:00',
                'ticket_price'  => 0,
                'max_capacity'  => null,
            ],
        ];
    }
}
