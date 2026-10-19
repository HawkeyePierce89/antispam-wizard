<?php

use PHPUnit\Framework\TestCase;

/**
 * Pins the quality of the shipped seed corpus, not just its presence.
 *
 * The corpus is the only thing the Bayesian check knows out of the box, and
 * the 1.0.0 release candidate shipped one that could not flag
 * "продам гараж, пишите 89139991205": every Cyrillic spam sample was a
 * 7–10-word advertising slogan (pharmacy, casino, credit), so a short
 * classified ad with a phone number shared no token with any of them. These
 * tests train the real per-script classifiers from the real data files and
 * hold them to a floor on held-out samples the corpus was not written from,
 * so a corpus edit that quietly loses a genre goes red here rather than on a
 * live site.
 */
class CorpusQualityTest extends TestCase {

    /** Scripts the held-out fixtures cover — now all ten script groups. */
    private const SCRIPTS = array( 'latin', 'cyrillic', 'cjk', 'arabic', 'devanagari', 'greek', 'hebrew', 'thai', 'bengali', 'indic' );

    /** Share of held-out spam that must score above the shipped threshold. */
    private const MIN_RECALL = 0.90;

    /** Share of held-out ham that may score above the shipped threshold. */
    private const MAX_FALSE_POSITIVE_RATE = 0.04;

    /**
     * Ham/spam token balance band.
     *
     * Laplace smoothing pushes every unknown word toward the category with
     * fewer total tokens, so a corpus whose ham and spam totals drift apart
     * makes all-unknown comments drift with them. Cyrillic and Latin drifted
     * before this work and are exempt as a known gap.
     */
    private const MIN_TOKEN_RATIO = 0.85;
    private const MAX_TOKEN_RATIO = 1.20;
    private const TOKEN_RATIO_EXEMPT = array( 'cyrillic', 'latin' );

    /**
     * Byte limit a cached model unit is held to here.
     *
     * The per-item limit a persistent object cache imposes is 1 048 576 bytes
     * -- memcached's default `-I`, which Redis drop-ins inherit as a convention
     * -- *less* the allowance ASW_Model_Store holds back for the cache item's
     * own overhead, because that is the limit production actually prunes at.
     * Flagging at the raw figure instead left a kilobyte-wide band in which a
     * unit passed every guard here and in dev/bench.php and was still
     * pruned on a live site.
     */
    private const UNIT_SIZE_LIMIT = ASW_Model_Store::MAX_CACHED_UNIT_BYTES
        - ASW_Model_Store::CACHE_ITEM_OVERHEAD_BYTES;

    /**
     * Learned samples of entirely new vocabulary the unbounded-history
     * measurement stores.
     *
     * Sized to overrun both bounds the design leans on rather than to sit
     * comfortably under them: 1 300 samples of LEARNED_SAMPLE_WORDS words each
     * tokenise to roughly 800 000 bytes of learned similarity lists -- six
     * times ASW_Model_Store::LEARNED_SIMILARITY_BUDGET -- and their 52 000
     * fresh tokens take the cyrillic spam dictionary all the way to
     * ASW_Naive_Bayes::MAX_DICTIONARY_SIZE. A site with that history is
     * exactly the one whose units used to cross the object-cache item limit.
     */
    private const LEARNED_SAMPLE_HISTORY = 1300;

    /**
     * Words per learned sample in the unbounded-history measurement.
     *
     * A real sample is a whole moderated comment and has no such bound, so
     * this fixes the *shape* of the measurement rather than modelling a limit
     * -- see test_an_unbounded_learning_history_still_fits_every_unit().
     */
    private const LEARNED_SAMPLE_WORDS = 40;

    /**
     * Short, link-free, stop-word-free spam that only the classifier can
     * catch. Each must be flagged through the real check at the shipped
     * default settings — including the default min_message_length, which is
     * what silently exempted the first entry on antispamwizard.com.
     *
     * No canary may equal a corpus or held-out fixture line
     * (test_canaries_are_not_corpus_or_fixture_lines()): a canary that is a
     * fixture line re-asserts what the held-out tests already measure. The
     * original Cyrillic and Latin entries were fixture lines, so each was
     * nudged — the garage ad, the live miss this class was written for, by
     * its last phone digit, which ASW_Tokenizer folds into the same phone
     * marker either way; the others by a word.
     */
    private const CANARIES = array(
        'cyrillic' => array(
            'продам гараж, пишите 89139991206',
            'Куплю квартиру в вашем районе, наличные, тел 8-916-123-45-67',
            'Пишите в телеграм @fast_money, доход от 5000 в день',
            'Раскрутка инстаграм, подписчики дёшево, вотсап 89001234567',
            'Займ до зарплаты за 10 минут, без справок, одобрение 100%',
            // The second live miss on antispamwizard.com: three words, none of
            // them in the 1.0.0 corpus, no phone, no link.
            'Зарубежный трафик нагон',
            'Нагон трафика на сайт, дешево',
        ),
        'latin'    => array(
            'Selling my garage, call 5551234567',
            'Contact me on WhatsApp +1 415 555 0135 for quick loans',
            'Write my essay cheap, telegram @essayhelper2024',
            'Earn $600 a day from home, text 202-555-0198',
            'Great post! Visit my blog for more tips like this',
            'Cheap targeted traffic for your site',
            'Buy followers fast, no password needed',
        ),
        'cjk'      => array(
            '出售二手沙发联系电话 13800138000 价格优惠',
            '网站引流服务保证首页排名低价高效',
            '想了解更多请访问我的网站有免费赚钱教程',
            'オンライン無料ボーナス今すぐ登録で一万円もらえる',
        ),
        'thai'     => array(
            'ขายโซฟามือสองติดต่อ 0812345678 ราคาถูกมาก',
            'เพิ่มทราฟฟิกเว็บไซต์การันตีอันดับหนึ่งราคาถูก',
            'สนใจดูเพิ่มเติมแวะชมเว็บผมมีเคล็ดลับหาเงินฟรี',
            'คาสิโนออนไลน์ฟรีโบนัสสมัครเลยตอนนี้รับเงินทันที',
        ),
        'arabic'   => array(
            'شقة للبيع اتصل 0551234567 سعر ممتاز موقع رائع',
            'زيادة زيارات موقعك ضمان الصفحة الأولى بسعر رخيص',
            'زوروا موقعي للمزيد نصائح ربح مجانية داخل',
            'كازينو على الإنترنت مكافأة مجانية سجل الآن فورا',
        ),
        'devanagari' => array(
            'सेकंड हैंड सोफा बेचना है संपर्क 9876543210 दाम कम',
            'वेबसाइट ट्रैफिक बढ़ाएं गारंटी पहले पेज पर सस्ता',
            'अधिक जानकारी के लिए मेरी साइट देखें मुफ्त कमाई टिप्स',
            'माझे मराठी ब्लॉग पहा फ्री पैसे कमवण्याच्या टिप्स आहेत',
        ),
        'bengali'  => array(
            'পুরানো সোফা বিক্রি করব যোগাযোগ 01712345678 দাম কম',
            'ওয়েবসাইট ট্রাফিক বাড়ান গ্যারান্টি প্রথম পাতায় সস্তায়',
            'আরও জানতে আমার সাইট দেখুন ফ্রি আয়ের টিপস আছে',
            'অনলাইন ক্যাসিনো ফ্রি বোনাস এখনই রেজিস্ট্রেশন করুন',
        ),
        'indic'    => array(
            'பழைய சோபா விற்பனை தொடர்பு 9876543210 விலை குறைவு',
            'వెబ్సైట్ ట్రాఫిక్ పెంచండి గ్యారంటీ మొదటి పేజీ చౌకగా',
            'ನನ್ನ ಸೈಟ್ ನೋಡಿ ಉಚಿತ ಹಣ ಗಳಿಕೆ ಟಿಪ್ಸ್ ಇವೆ',
            'ഓൺലൈൻ കാസിനോ സൗജന്യ ബോണസ് ഇപ്പോൾ രജിസ്റ്റർ ചെയ്യൂ',
        ),
        'greek'    => array(
            'Πωλείται καναπές μεταχειρισμένος καλέστε 6912345678 τιμή καλή',
            'Αύξηση επισκεψιμότητας εγγυημένη πρώτη σελίδα φθηνά',
            'Επισκεφθείτε το site μου δωρεάν συμβουλές για κέρδη',
            'Καζίνο online δωρεάν μπόνους εγγραφείτε τώρα αμέσως',
        ),
        'hebrew'   => array(
            'ספה יד שנייה למכירה צרו קשר 0521234567 מחיר טוב',
            'קידום תנועה לאתר מובטח עמוד ראשון בזול',
            'בקרו באתר שלי יש טיפים חינם להרוויח כסף',
            'קזינו אונליין בונוס חינם הירשמו עכשיו מיד',
        ),
    );

    /**
     * Ordinary short comments that must stay ham at the same settings —
     * the lowered length gate lets the classifier see them for the first time.
     *
     * Same rule as CANARIES: none of these may be a corpus or fixture line,
     * which is why the original Cyrillic and Latin entries carry one extra
     * word.
     */
    private const HAM_CANARIES = array(
        'cyrillic' => array(
            'Спасибо, очень помогло мне',
            'Не согласен с автором, но написано очень интересно',
            'А можно подробнее про второй пункт, пожалуйста?',
            'Пробовал этот способ, у меня к сожалению не сработало',
            // Praise words must stay ham even though the flattery-plus-own-site
            // spam genre opens with them: a corpus expansion once turned
            // "Отличная статья" into 0.97 spam.
            'Отличная статья, спасибо',
            'Не работает с Contact Form 7',
        ),
        'latin'    => array(
            'Thanks, this helped a lot!',
            'I disagree with the conclusion, but the article is well written overall',
            'Could you expand on the second point a bit more?',
            'Thank you for this helpful article. I really enjoyed reading it this morning.',
            'Great post, thanks for sharing',
            'Thanks for the tips, very helpful',
            'Does it work with Contact Form 7?',
        ),
        'cjk'      => array(
            '感谢分享这篇文章收获很大写得非常好',
            '第二段有一个错别字请作者有空修改一下',
            '升级到 WordPress 6.4 后速度提升了 3 倍',
            '最近忙着搬家暂时没空写博客不过流量还算稳定',
        ),
        'thai'     => array(
            'ขอบคุณสำหรับบทความดีดีครับ',
            'ย่อหน้าที่สองมีคำผิดนะครับแจ้งไว้ก่อน',
            'อัปเกรดเป็น WordPress 6.4 แล้วเร็วขึ้น 3 เท่า',
            'ช่วงนี้ยุ่งเรื่องย้ายบ้านแต่ทราฟฟิกเว็บยังนิ่ง',
        ),
        'arabic'   => array(
            'شكرا على المقال الرائع استفدت كثيرا',
            'وجدت خطأ مطبعيا في الفقرة الثانية للعلم',
            'بعد الترقية إلى ووردبريس 6.4 السرعة تضاعفت 3 مرات',
            'مشغول بالنقل هذه الأيام لكن زيارات الموقع مستقرة',
        ),
        'devanagari' => array(
            'बहुत अच्छा लेख धन्यवाद',
            'दूसरे पैराग्राफ में टाइपो है कृपया देखें',
            'वर्डप्रेस 6.4 पर अपग्रेड के बाद गति 3 गुना बढ़ी',
            'आजकल शिफ्टिंग में व्यस्त हूं पर ट्रैफिक स्थिर है',
        ),
        'bengali'  => array(
            'চমৎকার লেখা ধন্যবাদ',
            'দ্বিতীয় অনুচ্ছেদে একটি টাইপো আছে জানাচ্ছি',
            'ওয়ার্ডপ্রেস 6.4 এ আপগ্রেডের পর গতি 3 গুণ বেড়েছে',
            'এই সপ্তাহে বাসা বদলে ব্যস্ত তবে ট্রাফিক স্থিতিশীল',
        ),
        'indic'    => array(
            'மிக நல்ல கட்டுரை நன்றி',
            'రెండవ పేరాలో టైపో ఉంది దయచేసి చూడండి',
            'ವರ್ಡ್ಪ್ರೆಸ್ 6.4 ಗೆ ಅಪ್ಗ್ರೇಡ್ ನಂತರ ವೇಗ 3 ಪಟ್ಟು ಹೆಚ್ಚಾಗಿದೆ',
            'ഈ ആഴ്ച വീട് മാറുന്ന തിരക്കിലാണ് പക്ഷേ ട്രാഫിക് സ്ഥിരമാണ്',
        ),
        'greek'    => array(
            'Ευχαριστώ για το ωραίο άρθρο',
            'Βρήκα τυπογραφικό λάθος στη δεύτερη παράγραφο',
            'Μετά την αναβάθμιση σε WordPress 6.4 η ταχύτητα τριπλασιάστηκε',
            'Αυτή την εβδομάδα μετακομίζω αλλά η επισκεψιμότητα σταθερή',
        ),
        'hebrew'   => array(
            'תודה על המאמר המצוין',
            'מצאתי שגיאת כתיב בפסקה השנייה',
            'אחרי שדרוג לוורדפרס 6.4 המהירות גדלה פי 3',
            'עסוק במעבר דירה השבוע אבל התנועה באתר יציבה',
        ),
    );

    private ASW_Training_Data $training_data;

    /** @var string[]|null Memoised learned-sample vocabulary. */
    private ?array $novel_vocabulary = null;

    /** @var array<string, true>|null Memoised corpus token set. */
    private ?array $corpus_vocabulary = null;

    protected function setUp(): void {
        parent::setUp();
        global $wp_options, $wp_transients, $wpdb, $wp_using_ext_object_cache;
        // The unit-size tests write all eleven model units, so $wp_transients
        // is reset here and in tearDown() alongside $wp_options -- leaving them
        // behind would make a later test read a unit this class built. The
        // unbounded-history test seeds the samples table for the same reason
        // $wpdb is reset on both sides: its rows and registered columns are global
        // state, so leaving them behind would make a later test -- in this
        // class or another, under --random-order -- read samples it never
        // inserted.
        $wp_options    = array();
        $wp_transients = array();
        // Every size measurement below is of what a *persistent object cache*
        // is handed, since that is the only backend the 1 048 576-byte per-item
        // limit belongs to -- and the only one ASW_Model_Store stores a unit
        // compressed in. Without an external cache a transient is a `longtext`
        // option row with no per-item limit, and the store writes the plain
        // array there because wpdb will not take a deflate stream into a
        // utf8mb4 column at all.
        $wp_using_ext_object_cache = true;
        // None of the three ASW_TESTING switches is reset by reset_tables(),
        // so all three are reset on both sides here for the same reason
        // ModelStoreTest does: a lowered limit -- or a zlib this class did not
        // turn off -- left behind would measure another class's units against
        // a bound they were never built for under --random-order.
        $GLOBALS['asw_max_cached_unit_bytes'] = null;
        $GLOBALS['asw_zlib_unavailable']      = false;
        $GLOBALS['asw_gzcompress_fails']      = false;
        $wpdb->reset_tables();
        $this->training_data = new ASW_Training_Data();
    }

    protected function tearDown(): void {
        global $wp_options, $wp_transients, $wpdb, $wp_using_ext_object_cache;
        $wp_options                           = array();
        $wp_transients                        = array();
        $wp_using_ext_object_cache            = false;
        $GLOBALS['asw_max_cached_unit_bytes'] = null;
        $GLOBALS['asw_zlib_unavailable']      = false;
        $GLOBALS['asw_gzcompress_fails']      = false;
        $wpdb->reset_tables();
        parent::tearDown();
    }

    private function trained_classifier( string $script ): ASW_Naive_Bayes {
        $classifier = new ASW_Naive_Bayes( new ASW_Tokenizer(), $script );
        $this->training_data->train_script_classifier( $classifier, $script );
        return $classifier;
    }

    private function shipped_check(): ASW_Bayesian_Check {
        $classifiers = array();
        foreach ( self::SCRIPTS as $script ) {
            $classifiers[ $script ] = $this->trained_classifier( $script );
        }
        $combined = new ASW_Naive_Bayes();
        $this->training_data->train_combined_classifier( $combined );
        return new ASW_Bayesian_Check( $classifiers, $combined );
    }

    /** @return string[] */
    private function fixture( string $category, string $script ): array {
        $path  = __DIR__ . '/fixtures/corpus/' . $category . '-' . $script . '.txt';
        $lines = $this->training_data->load_samples( $path );
        $this->assertGreaterThanOrEqual( 50, count( $lines ), "fixture $path is too small to measure anything" );
        return $lines;
    }

    /** @return string[] */
    private function corpus( string $category, string $script ): array {
        return $this->training_data->load_samples(
            $this->training_data->get_script_file_path( $script, $category )
        );
    }

    public function test_short_spam_canaries_are_flagged_at_the_shipped_defaults(): void {
        $check = $this->shipped_check();
        foreach ( self::CANARIES as $script => $texts ) {
            foreach ( $texts as $text ) {
                $result = $check->check( array( 'comment_content' => $text ) );
                $this->assertTrue(
                    $result->is_spam(),
                    "[$script] expected spam for \"$text\": " . $result->get_details()
                );
            }
        }
    }

    public function test_short_ham_canaries_stay_ham_at_the_shipped_defaults(): void {
        $check = $this->shipped_check();
        foreach ( self::HAM_CANARIES as $script => $texts ) {
            foreach ( $texts as $text ) {
                $result = $check->check( array( 'comment_content' => $text ) );
                $this->assertFalse(
                    $result->is_spam(),
                    "[$script] expected ham for \"$text\": " . $result->get_details()
                );
            }
        }
    }

    public function test_held_out_spam_recall_meets_the_floor(): void {
        $threshold = ASW_Settings::get( 'bayesian_threshold' );
        foreach ( self::SCRIPTS as $script ) {
            $classifier = $this->trained_classifier( $script );
            $samples    = $this->fixture( 'spam', $script );
            $missed     = array();
            foreach ( $samples as $text ) {
                $p = $classifier->classify( $text );
                if ( ( $p['spam'] ?? 0.0 ) <= $threshold ) {
                    $missed[] = sprintf( '%.2f %s', $p['spam'] ?? 0.0, $text );
                }
            }
            $recall = 1 - count( $missed ) / count( $samples );
            $this->assertGreaterThanOrEqual(
                self::MIN_RECALL,
                $recall,
                sprintf( "[%s] recall %.2f, missed:\n  %s", $script, $recall, implode( "\n  ", $missed ) )
            );
        }
    }

    public function test_held_out_ham_false_positive_rate_stays_under_the_ceiling(): void {
        $threshold = ASW_Settings::get( 'bayesian_threshold' );
        foreach ( self::SCRIPTS as $script ) {
            $classifier = $this->trained_classifier( $script );
            $samples    = $this->fixture( 'ham', $script );
            $flagged    = array();
            foreach ( $samples as $text ) {
                $p = $classifier->classify( $text );
                if ( ( $p['spam'] ?? 0.0 ) > $threshold ) {
                    $flagged[] = sprintf( '%.2f %s', $p['spam'], $text );
                }
            }
            $rate = count( $flagged ) / count( $samples );
            $this->assertLessThanOrEqual(
                self::MAX_FALSE_POSITIVE_RATE,
                $rate,
                sprintf( "[%s] false positive rate %.2f, flagged:\n  %s", $script, $rate, implode( "\n  ", $flagged ) )
            );
        }
    }

    public function test_corpus_covers_short_comments(): void {
        // Spam is short and people write long; a corpus of 60-character
        // slogans only teaches the classifier the slogans. At least a third
        // of each spam file must be under 50 characters, at least a tenth of
        // each ham file too.
        foreach ( self::SCRIPTS as $script ) {
            foreach ( array( 'spam' => 1 / 3, 'ham' => 1 / 10 ) as $category => $floor ) {
                $lines = $this->corpus( $category, $script );
                $short = count( array_filter( $lines, static fn( string $l ): bool => mb_strlen( $l, 'UTF-8' ) < 50 ) );
                $this->assertGreaterThanOrEqual(
                    $floor,
                    $short / max( 1, count( $lines ) ),
                    "[$script/$category] only $short of " . count( $lines ) . ' samples are under 50 characters'
                );
            }
        }
    }

    public function test_corpus_has_no_duplicates_and_no_line_in_both_categories(): void {
        $scripts = array_keys( ASW_Script_Detector::SCRIPT_GROUPS );
        foreach ( $scripts as $script ) {
            $spam = array_map( 'mb_strtolower', $this->corpus( 'spam', $script ) );
            $ham  = array_map( 'mb_strtolower', $this->corpus( 'ham', $script ) );
            $this->assertSame( count( $spam ), count( array_unique( $spam ) ), "[$script] duplicate spam samples" );
            $this->assertSame( count( $ham ), count( array_unique( $ham ) ), "[$script] duplicate ham samples" );
            $both = array_intersect( $spam, $ham );
            $this->assertSame( array(), array_values( $both ), "[$script] samples present in both spam and ham" );
        }
    }

    public function test_corpus_token_counts_stay_balanced_between_categories(): void {
        $tokenizer = new ASW_Tokenizer( array() );
        $report    = array();
        $failures  = array();
        foreach ( self::SCRIPTS as $script ) {
            $spam_tokens = 0;
            foreach ( $this->corpus( 'spam', $script ) as $line ) {
                $spam_tokens += count( $tokenizer->tokenize_for_script( $line, $script ) );
            }
            $ham_tokens = 0;
            foreach ( $this->corpus( 'ham', $script ) as $line ) {
                $ham_tokens += count( $tokenizer->tokenize_for_script( $line, $script ) );
            }
            $ratio     = $spam_tokens ? $ham_tokens / $spam_tokens : 0.0;
            $report[]  = sprintf( '[%s] %d spam / %d ham, ham/spam %.3f', $script, $spam_tokens, $ham_tokens, $ratio );
            if ( in_array( $script, self::TOKEN_RATIO_EXEMPT, true ) ) {
                continue;
            }
            if ( $ratio < self::MIN_TOKEN_RATIO || $ratio > self::MAX_TOKEN_RATIO ) {
                $failures[] = sprintf( '[%s] ratio %.3f outside %.2f-%.2f', $script, $ratio, self::MIN_TOKEN_RATIO, self::MAX_TOKEN_RATIO );
            }
        }
        $this->assertSame(
            array(),
            $failures,
            "Token balance outside band; all scripts:\n  " . implode( "\n  ", $report ) . "\nFailures:\n  " . implode( "\n  ", $failures )
        );
    }

    /**
     * Every cached model unit must fit the object cache's per-item limit.
     *
     * This is a standing constraint, not a one-off measurement, and it is the
     * corpus that moves it: the combined unit's classifier state trains on
     * every script's samples, so growing any script's corpus grows it. When a
     * unit crosses the bound a persistent object cache refuses the item
     * silently -- `set_transient()` reports nothing, the next read misses, and
     * every submission pays a full cold rebuild for the unit's whole life with
     * no signal anywhere. That is the exact regression the packed
     * representation was written to remove, so it is asserted here rather than
     * left to `dev/bench.php`, which only runs by hand against a live
     * stack.
     *
     * What is measured is the **stored** form. A unit is cached compressed, so
     * the bytes a persistent object cache has to accept are the envelope's and
     * not the payload's, and the limit applies to those. The uncompressed size
     * is printed beside it, so a corpus edit that moves the budget can be seen
     * to have moved the payload rather than merely the compression ratio.
     *
     * The store is built with no repository, so the measurement is of the
     * shipped corpus alone; a site's own training samples are added on top of
     * it, which is what
     * test_an_unbounded_learning_history_still_fits_every_unit() covers.
     */
    public function test_every_cached_model_unit_fits_the_object_cache_item_limit(): void {
        $this->assert_units_fit( $this->measure_units( null ) );
    }

    /**
     * A site that never stops learning must still fit every unit.
     *
     * A unit is the shipped corpus *plus* every training sample the site has
     * added, and nothing in WordPress bounds the second half: every Spam / Not
     * Spam decision anywhere stores one, and they enter the unit exactly as
     * corpus lines do -- a script's samples grow its own unit, and the combined
     * unit trains on all of them. Left unbounded that history crosses the
     * object-cache item limit on a busy site, after which the cache refuses the
     * write silently and every submission in that writing system pays a cold
     * rebuild for good. Two bounds stop it, and this test drives a history past
     * both of them at once:
     *
     * - the similarity corpus takes only the newest
     *   ASW_Model_Store::LEARNED_SIMILARITY_BUDGET packed bytes of learned
     *   spam, and
     * - the classifier's vocabulary stops at
     *   ASW_Naive_Bayes::MAX_DICTIONARY_SIZE tokens per category.
     *
     * LEARNED_SAMPLE_HISTORY samples of LEARNED_SAMPLE_WORDS words each go in
     * under `cyrillic`, which owns the largest per-script unit, so the tightest
     * pair is measured (that unit and the combined one), and all of them are
     * stored as `spam`, the heavier category: a spam sample enters the script
     * unit's similarity corpus *and* its classifier, a ham sample only the
     * classifier. Their vocabulary is entirely new, which is pessimistic per
     * token rather than per comment -- a real learned comment reuses the
     * corpus's words and the other samples'.
     *
     * The measurement is over the compressed stored form, which is what an
     * object cache is handed, and it carries its own anti-vacuity guards: every
     * insert lands, every token is unique and absent from the shipped corpus,
     * the samples really reached both units, the spam dictionary really did
     * reach its cap, and the learned half of the similarity corpus really was
     * truncated by the budget. Without that last one the test would pass just
     * as well if the cap silently did nothing and the whole history still
     * happened to fit.
     */
    public function test_an_unbounded_learning_history_still_fits_every_unit(): void {
        global $wpdb;
        $wpdb->reset_tables();
        $wpdb->register_table_columns(
            $wpdb->prefix . 'asw_samples',
            array( 'id', 'script', 'category', 'content', 'content_hash', 'comment_id', 'email_hash', 'created_at' )
        );

        $repository = new ASW_Sample_Repository( $wpdb );
        for ( $i = 0; $i < self::LEARNED_SAMPLE_HISTORY; $i++ ) {
            // Every insert has to land, or the measurement is quietly taken over
            // fewer samples than it claims: two samples sharing a body would
            // come back 'exists' and a refused write false, and the size
            // comparisons below only prove that *some* sample reached each
            // unit, so an underloaded run would pass.
            $this->assertSame(
                'inserted',
                $repository->insert(
                    'cyrillic',
                    'spam',
                    $this->novel_cyrillic_sample( $i ),
                    $i + 1
                ),
                "learned sample $i was not stored, so the history is measured over too few samples"
            );
        }

        $this->assertSame(
            self::LEARNED_SAMPLE_HISTORY,
            count( $repository->find_all( 'cyrillic', 'spam' ) ),
            'the repository does not hold the full set of learned samples'
        );

        // What makes the measurement pessimistic per token is that every one
        // of those words is one the classifier does not already have. A
        // repeat, or a word the corpus carries, would be absorbed into an
        // existing `token count` pair and measure nothing -- and would do so
        // silently, since the size assertions below only prove that *some*
        // sample reached each unit.
        $vocabulary = $this->novel_vocabulary();
        $this->assertCount(
            self::LEARNED_SAMPLE_HISTORY * self::LEARNED_SAMPLE_WORDS,
            array_unique( $vocabulary ),
            'the learned samples repeat a token, so fewer classifier keys are added than the measurement claims'
        );
        $this->assertSame(
            array(),
            array_values( array_intersect_key( array_flip( $vocabulary ), $this->corpus_vocabulary() ) ),
            'a learned token is already in the shipped corpus, so it adds no classifier key'
        );

        $with_samples = $this->measure_units( $repository );

        // The samples must actually be in the units, or this test measures the
        // corpus a second time and can never go red: they reach a unit through
        // ASW_Training_Data, so a store handed a repository but a repositoryless
        // ASW_Training_Data builds byte-identical units. The comparison is on
        // the uncompressed payload, since a compressed size can move for
        // reasons of its own.
        $corpus_only = $this->measure_units( null );
        $this->assertGreaterThan(
            $corpus_only['asw_classifiers_cyrillic']['raw'],
            $with_samples['asw_classifiers_cyrillic']['raw'],
            'the learned samples never reached the cyrillic unit, so nothing was measured'
        );
        $this->assertGreaterThan(
            $corpus_only['asw_classifiers_combined']['raw'],
            $with_samples['asw_classifiers_combined']['raw'],
            'the learned samples never reached the combined unit, so nothing was measured'
        );

        // The history is meant to exhaust the classifier bound as well as the
        // similarity one; a run that did not reach the cap is measuring a
        // smaller worst case than it claims.
        $this->assertSame(
            ASW_Naive_Bayes::MAX_DICTIONARY_SIZE,
            $with_samples['asw_classifiers_cyrillic']['dictionary']['spam'] ?? 0,
            'the learned history did not fill the spam dictionary, so the classifier half is under-measured'
        );

        // The learned half of the similarity corpus is the difference between
        // the two builds: the shipped lists are identical in both, so whatever
        // is left is exactly what admit_within_budget() paid for.
        $learned_bytes = $with_samples['asw_classifiers_cyrillic']['samples_bytes']
            - $corpus_only['asw_classifiers_cyrillic']['samples_bytes'];
        $learned_lists = $with_samples['asw_classifiers_cyrillic']['samples_count']
            - $corpus_only['asw_classifiers_cyrillic']['samples_count'];

        $this->assertGreaterThan(
            0,
            $learned_lists,
            'no learned sample reached the similarity corpus, so the budget was not exercised'
        );
        $this->assertLessThanOrEqual(
            ASW_Model_Store::LEARNED_SIMILARITY_BUDGET,
            $learned_bytes,
            sprintf(
                'the learned similarity corpus is %d bytes, over the %d-byte budget',
                $learned_bytes,
                ASW_Model_Store::LEARNED_SIMILARITY_BUDGET
            )
        );
        $this->assertLessThan(
            self::LEARNED_SAMPLE_HISTORY,
            $learned_lists,
            'every learned sample fitted the budget, so the cap never bound and this test proves nothing about it'
        );

        $this->assert_units_fit( $with_samples );
    }

    /**
     * The arithmetic the budget was chosen from, as an executable assertion.
     *
     * LEARNED_SIMILARITY_BUDGET is not a guess: it is what is left under the
     * 1 048 576-byte object-cache item limit once the worst unit a site can
     * reach is paid for. That worst case is not something a corpus test can
     * grow into -- it needs a full ASW_Naive_Bayes::MAX_DICTIONARY_SIZE
     * dictionary in *both* categories, which no realistic fixture reaches in
     * the ham half -- so it is synthesised here instead: 50 000 novel tokens
     * per category, the whole shipped Cyrillic similarity corpus (the largest
     * per-script one), and a learned tail filling the budget exactly.
     *
     * Everything is packed and compressed through ASW_Model_Store's own code
     * by reflection rather than by a copy of it, so the measurement cannot
     * drift from the format the plugin actually stores: a change to the packed
     * representation or to COMPRESSION_LEVEL is measured here the moment it
     * lands.
     *
     * What this case does *not* reach is the dictionary whose *tokens* are
     * long: the ones drawn here are 4-10 Cyrillic characters, i.e. 8-20 bytes,
     * where ASW_Tokenizer puts no cap on a token's length at all and a full
     * two-category dictionary of 10-character CJK tokens compresses to more
     * than twice the limit on the classifier alone. That case is reachable and
     * is covered by the test below, through the admission control that bounds
     * it -- ASW_Model_Store::storable_unit().
     */
    public function test_a_full_dictionary_worst_case_unit_fits_the_object_cache_item_limit(): void {
        if ( ! function_exists( 'gzcompress' ) || ! function_exists( 'gzuncompress' ) ) {
            $this->markTestSkipped(
                'zlib is absent, so a unit is stored uncompressed; the worst case is over the limit there and is a recorded residual'
            );
        }

        $store = new ASW_Model_Store(
            new ASW_Tokenizer(),
            array(),
            new ASW_Training_Data( null ),
            null
        );

        $shipped = $this->shipped_similarity_lists( $store, 'cyrillic' );
        $this->assertNotEmpty( $shipped, 'the shipped Cyrillic similarity corpus is empty, so the worst case is under-measured' );

        // Two full dictionaries of tokens that share nothing, which is the
        // worst the packed state can be for a given token length.
        $words      = $this->lcg_words( 2 * ASW_Naive_Bayes::MAX_DICTIONARY_SIZE, 424242 );
        $categories = array(
            'spam' => array_slice( $words, 0, ASW_Naive_Bayes::MAX_DICTIONARY_SIZE ),
            'ham'  => array_slice( $words, ASW_Naive_Bayes::MAX_DICTIONARY_SIZE ),
        );

        $token_counts = array();
        $token_totals = array();
        foreach ( $categories as $category => $tokens ) {
            $counts = array();
            foreach ( $tokens as $token ) {
                $counts[ $token ] = 1 + strlen( $token ) % 9;
            }
            $this->assertCount(
                ASW_Naive_Bayes::MAX_DICTIONARY_SIZE,
                $counts,
                "the $category dictionary is not full, so the worst case is under-measured"
            );
            $token_counts[ $category ] = $counts;
            $token_totals[ $category ] = array_sum( $counts );
        }

        $packed_state = $this->store_call(
            $store,
            'pack_classifier_state',
            array(
                array(
                    'token_counts' => $token_counts,
                    'token_totals' => $token_totals,
                    'doc_counts'   => array( 'spam' => 5000, 'ham' => 5000 ),
                ),
            )
        );

        // A learned tail spending the budget down to its last admissible list,
        // which is the most admit_within_budget() can ever let through.
        $pool     = $this->lcg_words( 12000, 20260914 );
        $learned  = array();
        $spent    = 0;
        $rejected = null;
        foreach ( array_chunk( $pool, self::LEARNED_SAMPLE_WORDS ) as $tokens ) {
            // What admit_within_budget() charges: the packed bytes plus the
            // array element the list is stored in.
            $cost = strlen( implode( ' ', $tokens ) ) + ASW_Model_Store::LEARNED_SAMPLE_OVERHEAD_BYTES;
            if ( $spent + $cost > ASW_Model_Store::LEARNED_SIMILARITY_BUDGET ) {
                $rejected = $cost;
                break;
            }
            $spent    += $cost;
            $learned[] = $tokens;
        }
        // Full to within one whole sample, which is as tight as
        // admit_within_budget() itself can ever be -- it admits whole lists or
        // none. A null here means the pool ran out first, so the tail is short
        // for a reason that has nothing to do with the budget.
        $this->assertNotNull(
            $rejected,
            'the synthetic word pool ran out before the budget did, so the worst case is under-measured'
        );
        $this->assertGreaterThan(
            ASW_Model_Store::LEARNED_SIMILARITY_BUDGET - $rejected,
            $spent,
            'the synthetic learned tail does not fill the budget, so the worst case is under-measured'
        );

        $unit = array(
            'fingerprint' => md5( 'worst-case' ),
            'classifier'  => $packed_state,
            'samples'     => array_merge(
                $shipped,
                $this->store_call( $store, 'pack_sample_tokens', array( $learned ) )
            ),
        );

        $stored = strlen( serialize( asw_test_wrap_unit( $unit ) ) );
        $raw    = strlen( serialize( $unit ) );

        $this->assertLessThan(
            self::UNIT_SIZE_LIMIT,
            $stored,
            sprintf(
                "The worst unit a site can reach is at or over the %d-byte limit a unit is pruned at:\n"
                    . "  %d B stored (%.1f%%), %d B uncompressed, %d B of margin.\n"
                    . "LEARNED_SIMILARITY_BUDGET is %d B and no longer buys what it was chosen to buy.",
                self::UNIT_SIZE_LIMIT,
                $stored,
                $stored / self::UNIT_SIZE_LIMIT * 100,
                $raw,
                self::UNIT_SIZE_LIMIT - $stored,
                ASW_Model_Store::LEARNED_SIMILARITY_BUDGET
            )
        );
    }

    /**
     * The dictionary that no budget can predict, bounded by weighing it.
     *
     * ASW_Naive_Bayes::MAX_DICTIONARY_SIZE caps the *count* of tokens a
     * category learns, not their length, and ASW_Tokenizer emits a whole
     * unbroken run as one token -- so a Cyrillic-dominant sample quoting CJK
     * keeps a 10-character run whole as one 30-byte token. A full two-category
     * dictionary of those packs to ~3.3 MB and compresses to ~2.2 MB, 211 % of
     * the object-cache item limit before a single similarity sample, which
     * memcached refuses silently: every submission in that writing system then
     * pays a cold rebuild for good.
     *
     * Neither compression-independent remedy closes it, and both were
     * measured: a **token-length cap** would have to sit at or under 12 bytes
     * -- 4 CJK characters, 6 Cyrillic ones -- where only 52 % of the shipped
     * corpus's own tokens survive and its longest, a 174-byte Thai run, is cut
     * by a factor of fourteen; and a **packed-byte budget** has to assume a
     * 1.0x ratio, so it would prune ordinary sites whose units compress
     * 2.5-3.5x and are nowhere near the limit.
     *
     * ASW_Model_Store::storable_unit() weighs the value it is about to write
     * instead, and drops the least frequent half of each category's vocabulary
     * until the cache can hold it. This drives the reachable failing case
     * through it and asserts the outcome: a stored value under the limit, and
     * a live classifier carrying exactly the model that was stored.
     */
    public function test_a_dictionary_of_long_tokens_is_pruned_until_the_unit_fits(): void {
        if ( ! function_exists( 'gzcompress' ) || ! function_exists( 'gzuncompress' ) ) {
            $this->markTestSkipped(
                'zlib is absent, so a unit is stored uncompressed; the admission control below still runs, but its measurement is of another form'
            );
        }

        $store = new ASW_Model_Store(
            new ASW_Tokenizer(),
            array(),
            new ASW_Training_Data( null ),
            null
        );

        // 10-character CJK tokens, 30 bytes each, sharing nothing -- the worst
        // the packed state can be at the count cap.
        $tokens     = $this->lcg_cjk_tokens( 2 * ASW_Naive_Bayes::MAX_DICTIONARY_SIZE, 10, 31337 );
        $categories = array(
            'spam' => array_slice( $tokens, 0, ASW_Naive_Bayes::MAX_DICTIONARY_SIZE ),
            'ham'  => array_slice( $tokens, ASW_Naive_Bayes::MAX_DICTIONARY_SIZE ),
        );

        $state = array(
            'token_counts' => array(),
            'token_totals' => array(),
            'doc_counts'   => array( 'spam' => 5000, 'ham' => 5000 ),
        );
        foreach ( $categories as $category => $list ) {
            $counts = array();
            foreach ( $list as $index => $token ) {
                $counts[ $token ] = 1 + $index % 9;
            }
            $this->assertCount(
                ASW_Naive_Bayes::MAX_DICTIONARY_SIZE,
                $counts,
                "the $category dictionary is not full, so the worst case is under-measured"
            );
            $state['token_counts'][ $category ] = $counts;
            $state['token_totals'][ $category ] = array_sum( $counts );
        }

        $classifier = new ASW_Naive_Bayes();
        $classifier->set_state( $state );
        $this->assertSame(
            $state['token_counts']['spam'],
            $classifier->get_state()['token_counts']['spam'],
            'premise: the synthetic state is one ASW_Naive_Bayes accepts'
        );

        $export = array(
            'fingerprint' => md5( 'long-token-worst-case' ),
            'classifier'  => $this->store_call( $store, 'pack_classifier_state', array( $state ) ),
            'samples'     => $this->shipped_similarity_lists( $store, 'cyrillic' ),
        );

        // The premise, asserted rather than assumed: without admission control
        // this is exactly the item a persistent object cache drops on the floor.
        $unbounded = strlen( serialize( asw_test_wrap_unit( $export ) ) );
        $this->assertGreaterThan(
            self::UNIT_SIZE_LIMIT,
            $unbounded,
            'the synthetic dictionary is not over the limit, so this test proves nothing about bounding one that is'
        );

        $stored = $this->store_call( $store, 'storable_unit', array( $classifier, $export ) );

        $this->assertIsArray( $stored, 'a unit over the limit must be pruned to fit, not dropped' );

        $size = strlen( serialize( $stored ) );
        $this->assertLessThan(
            self::UNIT_SIZE_LIMIT,
            $size,
            sprintf(
                "The reachable worst case is still at or over the %d-byte limit a unit is pruned at:\n"
                    . "  %d B stored after pruning, %d B before it.",
                self::UNIT_SIZE_LIMIT,
                $size,
                $unbounded
            )
        );

        // What was written is what this request classifies with, and it is a
        // model, not an empty one: halving stops at the first fit.
        $pruned = $classifier->get_state();
        foreach ( array( 'spam', 'ham' ) as $category ) {
            $this->assertLessThan(
                ASW_Naive_Bayes::MAX_DICTIONARY_SIZE,
                count( $pruned['token_counts'][ $category ] ),
                "the $category vocabulary was not pruned, so the unit fitted for some other reason"
            );
            $this->assertNotSame(
                array(),
                $pruned['token_counts'][ $category ],
                "the $category vocabulary was spent entirely, where halving should have stopped at the first fit"
            );
        }

        $served = asw_test_unwrap_unit( $stored );
        $this->assertIsArray( $served, 'the pruned unit must still be readable by the serving path' );
        $this->assertSame(
            $this->store_call( $store, 'pack_classifier_state', array( $pruned ) ),
            $served['classifier'],
            'the cached model and the one this request kept must be the same model'
        );
    }

    /**
     * Unique CJK tokens of a fixed character length, drawn reproducibly.
     *
     * A local LCG rather than mt_srand(), for the same reason lcg_words() uses
     * one: seeding the global sequence would perturb every later draw in the
     * suite.
     *
     * @param int $count  How many tokens.
     * @param int $length Characters per token.
     * @param int $seed   LCG seed.
     * @return array<int, string>
     */
    private function lcg_cjk_tokens( int $count, int $length, int $seed ): array {
        $first = 0x4E00;
        $span  = 0x9FA5 - $first;

        $state = $seed;
        $next  = static function () use ( &$state ): int {
            $state = ( $state * 1664525 + 1013904223 ) % 4294967296;
            return $state;
        };

        $tokens   = array();
        $seen     = array();
        $attempts = 0;
        while ( count( $tokens ) < $count ) {
            ++$attempts;
            $this->assertLessThan(
                $count * 10,
                $attempts,
                'the token draw could not be completed'
            );

            $token = '';
            for ( $i = 0; $i < $length; $i++ ) {
                $token .= mb_chr( $first + $next() % $span, 'UTF-8' );
            }

            if ( isset( $seen[ $token ] ) ) {
                continue;
            }

            $seen[ $token ] = true;
            $tokens[]       = $token;
        }

        return $tokens;
    }

    /**
     * Build and measure every cached unit over the shipped corpus.
     *
     * Each unit is measured in the form it is stored in -- compressed, which is
     * what the object cache's per-item limit applies to -- and then read back
     * through the store's own reader so the uncompressed payload and the
     * per-member figures below come from the format the plugin really writes
     * rather than from a copy of it here.
     *
     * @param ASW_Sample_Repository|null $repository Repository whose samples are added, or null for the corpus alone.
     * @return array<string, array{stored: int, raw: int, samples_bytes: int, samples_count: int, dictionary: array<string, int>}>
     */
    private function measure_units( ?ASW_Sample_Repository $repository ): array {
        global $wp_transients, $asw_max_cached_unit_bytes;
        $wp_transients = array();

        // Admission control is switched off for the build, and that is what
        // makes the assertion below mean anything. ASW_Model_Store::storable_unit()
        // prunes a unit's vocabulary until it is under the very limit
        // assert_units_fit() checks, so measuring what it wrote would assert
        // the write path's own postcondition: a corpus edit that doubled a
        // unit would be silently absorbed by halving the dictionary and the
        // guard would stay green. Weighing the *unpruned* unit is the question
        // this test exists to ask.
        $previous_limit            = $asw_max_cached_unit_bytes;
        $asw_max_cached_unit_bytes = PHP_INT_MAX;

        try {
            $store = new ASW_Model_Store(
                new ASW_Tokenizer(),
                array(),
                new ASW_Training_Data( $repository ),
                $repository
            );

            // Load every unit so each one is built and written. A per-script body
            // never reaches the combined unit, so it has to be asked for by name.
            foreach ( array_keys( ASW_Script_Detector::SCRIPT_GROUPS ) as $script ) {
                $store->script_classifier( $script );
                $store->script_sample_tokens( $script );
            }
            $store->combined_classifier();
            $store->combined_sample_tokens();
        } finally {
            $asw_max_cached_unit_bytes = $previous_limit;
        }

        $measurements = array();
        foreach ( ASW_Model_Store::unit_keys() as $key ) {
            $this->assertArrayHasKey(
                $key,
                $wp_transients,
                "unit $key was never cached, so this test would measure nothing"
            );

            $stored = $wp_transients[ $key ];
            // The bootstrap's set_transient() stub injects __ttl into an array
            // value; the payload a real site stores carries no such member.
            unset( $stored['__ttl'] );

            $unit = asw_test_unwrap_unit( $stored );
            $this->assertIsArray(
                $unit,
                "unit $key was cached in a form the store itself will not serve"
            );

            $samples    = $unit['samples'] ?? array();
            $packed     = $unit['classifier']['token_counts'] ?? array();
            $dictionary = array();
            foreach ( $packed as $category => $pairs ) {
                // Packed as "token count token count …", so a category's
                // vocabulary is half its segment count; an untrained category
                // packs to the empty string.
                $dictionary[ $category ] = '' === $pairs ? 0 : intdiv( count( explode( ' ', $pairs ) ), 2 );
            }

            $measurements[ $key ] = array(
                'stored'        => strlen( serialize( $stored ) ),
                'raw'           => strlen( serialize( $unit ) ),
                'samples_bytes' => array_sum( array_map( 'strlen', $samples ) ),
                'samples_count' => count( $samples ),
                'dictionary'    => $dictionary,
            );
        }

        return $measurements;
    }

    /**
     * The packed similarity lists a script's unit holds over the shipped
     * corpus alone.
     *
     * @param ASW_Model_Store $store  Store to build the unit with.
     * @param string          $script Script group name.
     * @return array<int, string> Packed sample strings.
     */
    private function shipped_similarity_lists( ASW_Model_Store $store, string $script ): array {
        global $wp_transients;
        $wp_transients = array();

        $store->script_sample_tokens( $script );

        $key = ASW_Model_Store::TRANSIENT_PREFIX . $script;
        $this->assertArrayHasKey( $key, $wp_transients, "unit $key was never cached" );

        $stored = $wp_transients[ $key ];
        unset( $stored['__ttl'] );
        $unit = asw_test_unwrap_unit( $stored );
        $this->assertIsArray( $unit, "unit $key was cached in a form the store itself will not serve" );

        return $unit['samples'];
    }

    /**
     * Call one of ASW_Model_Store's private packers.
     *
     * Reflection rather than a reimplementation: the point of the worst-case
     * measurement is that it is taken in the stored format, so a change to
     * that format has to move it.
     *
     * @param ASW_Model_Store $store  Store instance.
     * @param string          $method Private method name.
     * @param array           $args   Positional arguments.
     * @return mixed
     */
    private function store_call( ASW_Model_Store $store, string $method, array $args ) {
        // No setAccessible() call: it has had no effect since PHP 8.1 -- the
        // plugin's floor -- and is deprecated from 8.5, where it would put a
        // notice into a suite that runs with failOnWarning.
        $reflected = new ReflectionMethod( ASW_Model_Store::class, $method );

        return $reflected->invokeArgs( $store, $args );
    }

    /**
     * Assert every measured unit is under the object cache's per-item limit.
     *
     * @param array<string, array{stored: int, raw: int}> $measurements Per-unit measurements.
     */
    private function assert_units_fit( array $measurements ): void {
        $over   = array();
        $report = array();
        foreach ( $measurements as $key => $unit ) {
            if ( $unit['stored'] >= self::UNIT_SIZE_LIMIT ) {
                $over[] = $key;
            }
            $report[] = sprintf(
                '%s: %d B stored (%.1f%%), %d B uncompressed',
                $key,
                $unit['stored'],
                $unit['stored'] / self::UNIT_SIZE_LIMIT * 100,
                $unit['raw']
            );
        }

        $this->assertSame(
            array(),
            $over,
            "A cached unit is at or over the " . self::UNIT_SIZE_LIMIT . "-byte limit a unit is pruned at.\n"
                . "An object cache refuses such an item silently, putting every submission that needs\n"
                . "the unit on the cold path for good. Sizes:\n  " . implode( "\n  ", $report )
        );
    }

    /**
     * A learned sample whose every token is new to the corpus.
     *
     * Deterministic (a local, fixed-seed generator) so the measurement is
     * stable across runs, and deliberately pessimistic: a real learned comment
     * reuses the corpus's vocabulary, where every token here adds a fresh
     * `token count` pair to the packed classifier state of both the script
     * unit and the combined one -- until the dictionary reaches its cap, which
     * a history this long is meant to reach.
     *
     * The whole vocabulary is drawn once by novel_vocabulary(), which is where
     * "entirely new" is enforced rather than hoped for -- see that method.
     *
     * @param int $index Zero-based sample index.
     * @return string
     */
    private function novel_cyrillic_sample( int $index ): string {
        return implode(
            ' ',
            array_slice(
                $this->novel_vocabulary(),
                $index * self::LEARNED_SAMPLE_WORDS,
                self::LEARNED_SAMPLE_WORDS
            )
        );
    }

    /**
     * The learned samples' vocabulary: one word per token of the whole
     * measurement, every one of them new to the classifier.
     *
     * "New" has to be enforced rather than assumed. Drawing words at random
     * and hoping is exactly what the measurement claims not to be doing: a
     * word that repeats, or that the shipped corpus already carries, adds no
     * key to the packed classifier state, so the run is quietly less
     * pessimistic than it says -- and silently more so over time, since every
     * corpus edit widens the set a random Cyrillic word can collide with.
     * Every candidate is therefore rejected unless it is unseen *and* absent
     * from corpus_vocabulary(), which makes the fresh-token count exact by
     * construction.
     *
     * @return string[] LEARNED_SAMPLE_HISTORY * LEARNED_SAMPLE_WORDS words.
     */
    private function novel_vocabulary(): array {
        if ( null !== $this->novel_vocabulary ) {
            return $this->novel_vocabulary;
        }

        $this->novel_vocabulary = $this->lcg_words(
            self::LEARNED_SAMPLE_HISTORY * self::LEARNED_SAMPLE_WORDS,
            20260914,
            $this->corpus_vocabulary()
        );

        return $this->novel_vocabulary;
    }

    /**
     * Distinct deterministic Cyrillic pseudo-words.
     *
     * The generator is a local LCG rather than mt_rand(): seeding the latter
     * means mt_srand(), which mutates the process-global sequence, so these
     * tests would perturb whatever runs after them -- differently under
     * --random-order.
     *
     * @param int                 $count     How many words to draw.
     * @param int                 $seed      LCG seed.
     * @param array<string, true> $forbidden Words that must not be drawn.
     * @return string[]
     */
    private function lcg_words( int $count, int $seed, array $forbidden = array() ): array {
        $alphabet = preg_split( '//u', 'абвгдеёжзийклмнопрстуфхцчшщыэюя', -1, PREG_SPLIT_NO_EMPTY );
        $letters  = count( $alphabet );

        $state = $seed;
        $next  = static function () use ( &$state ): int {
            // Numerical Recipes' LCG, kept in this closure so the draw is
            // reproducible without touching the global mt_rand() sequence.
            $state = ( $state * 1664525 + 1013904223 ) % 4294967296;
            return $state;
        };

        $words    = array();
        $seen     = array();
        $attempts = 0;
        while ( count( $words ) < $count ) {
            ++$attempts;
            $this->assertLessThan(
                $count * 10,
                $attempts,
                'the word draw could not be completed: too few candidates survive the exclusions'
            );

            $length = 4 + $next() % 7;
            $word   = '';
            for ( $j = 0; $j < $length; $j++ ) {
                $word .= $alphabet[ $next() % $letters ];
            }

            if ( isset( $seen[ $word ] ) || isset( $forbidden[ $word ] ) ) {
                continue;
            }

            $seen[ $word ] = true;
            $words[]       = $word;
        }

        return $words;
    }

    /**
     * Every classifier key the shipped corpus itself contributes.
     *
     * Read through the real ASW_Tokenizer, so the set is exactly the one a
     * generated word could collide with, and over every data file rather than
     * the Cyrillic pair alone: the combined unit trains on all of them, so a
     * Cyrillic token sitting in the legacy files counts too.
     *
     * @return array<string, true> Token set.
     */
    private function corpus_vocabulary(): array {
        if ( null !== $this->corpus_vocabulary ) {
            return $this->corpus_vocabulary;
        }

        $tokenizer = new ASW_Tokenizer( array() );
        $tokens    = array();
        $files     = glob( ASW_PATH . 'data/*.txt' );
        $this->assertNotEmpty( $files, 'no corpus files found, so nothing could be checked for collisions' );

        foreach ( $files as $path ) {
            foreach ( $this->training_data->load_samples( $path ) as $line ) {
                foreach ( $tokenizer->tokenize( $line ) as $token ) {
                    $tokens[ $token ] = true;
                }
            }
        }

        $this->corpus_vocabulary = $tokens;
        return $tokens;
    }


    public function test_held_out_fixtures_do_not_leak_into_the_corpus(): void {
        foreach ( self::SCRIPTS as $script ) {
            foreach ( array( 'spam', 'ham' ) as $category ) {
                $corpus  = array_map( 'mb_strtolower', $this->corpus( $category, $script ) );
                $fixture = array_map( 'mb_strtolower', $this->fixture( $category, $script ) );
                $leaked  = array_intersect( $fixture, $corpus );
                $this->assertSame( array(), array_values( $leaked ), "[$script/$category] held-out lines copied into the corpus" );
            }
        }
    }

    public function test_canaries_are_not_corpus_or_fixture_lines(): void {
        foreach ( self::SCRIPTS as $script ) {
            foreach ( array( 'spam', 'ham' ) as $category ) {
                $path   = $this->training_data->get_script_file_path( $script, $category );
                $corpus = array_map( 'mb_strtolower', $this->training_data->load_samples( $path ) );
                $corpus_set = array_flip( $corpus );
                $fixture_path = __DIR__ . '/fixtures/corpus/' . $category . '-' . $script . '.txt';
                $fixture      = array_map( 'mb_strtolower', $this->training_data->load_samples( $fixture_path ) );
                $fixture_set  = array_flip( $fixture );
                $canaries = array_merge(
                    self::CANARIES[ $script ] ?? array(),
                    self::HAM_CANARIES[ $script ] ?? array()
                );
                foreach ( $canaries as $line ) {
                    $lc = mb_strtolower( $line, 'UTF-8' );
                    $this->assertArrayNotHasKey( $lc, $corpus_set, "[$script] canary equals corpus line: $line" );
                    $this->assertArrayNotHasKey( $lc, $fixture_set, "[$script] canary equals fixture line: $line" );
                }
            }
        }
    }
}
