<?php
// Editable home-page content. Defaults live here; edits are stored as JSON in settings('home_json').
function home_schema(): array {
    // type: text | area | list ; list items: array of [key,label,type]
    return [
        'hero' => ['Hero (top of page)', [
            ['tag', 'Top tag', 'text', 'G.C.E. Advanced Level Chemistry'], ['kicker', 'Small heading', 'text', 'GENUINE CHEMISTRY'],
            ['h1_red', 'Title – red word', 'text', 'Chemistry'], ['h1_mid', 'Title – second line start', 'text', 'made'], ['h1_blue', 'Title – blue word', 'text', 'clear.'],
            ['si_name', 'Name in Sinhala', 'text', 'තරිඳු කේ. විජේකෝන්'], ['quali', 'Qualification line', 'text', 'Tharidu K. Wijekoon · BSc (Chemistry, Physics)'],
            ['lead', 'Description', 'area', 'Theory, paper classes and online speed revision for A/L students — in Kegalle, Kurunegala and online. Understand the concepts, practise the papers, walk into the exam ready.'],
            ['btn1', 'Button 1 text', 'text', 'Create student account'], ['btn2', 'Button 2 text', 'text', 'See batches'],
            ['chips', 'Floating chips (3)', 'list', [['icon', 'Icon'], ['title', 'Title'], ['sub', 'Subtitle']], [
                ['icon' => 'book-open', 'title' => 'Theory', 'sub' => 'Senara · Kegalle'], ['icon' => 'file-text', 'title' => 'Paper classes', 'sub' => 'Kegalle & Kurunegala'], ['icon' => 'monitor-play', 'title' => 'Online revision', 'sub' => 'Learn from home']]],
        ]],
        'facts' => ['Facts strip (red bar)', [
            ['facts', 'Facts', 'list', [['icon', 'Icon'], ['title', 'Big text'], ['sub', 'Small text']], [
                ['icon' => 'graduation-cap', 'title' => 'BSc', 'sub' => 'Chemistry & Physics'], ['icon' => 'map-pin', 'title' => '2 centres', 'sub' => 'Kegalle · Kurunegala'],
                ['icon' => 'layers', 'title' => '3 class types', 'sub' => 'Theory · Paper · Online'], ['icon' => 'message-circle', 'title' => 'Direct support', 'sub' => 'On WhatsApp']]],
        ]],
        'batches' => ['Batches section', [
            ['b_tag', 'Tag', 'text', 'Batches open'], ['b_t1', 'Title – start', 'text', 'Discover'], ['b_t2', 'Title – red word', 'text', "Chemistry's"], ['b_t3', 'Title – end', 'text', 'Wonder'],
            ['b_lead', 'Sub text', 'text', 'Choose your A/L batch and reserve your seat.'],
        ]],
        'about' => ['About us', [
            ['about_tag', 'Tag', 'text', 'About your teacher'], ['about_title', 'Title', 'text', 'Learn chemistry the genuine way'],
            ['about_text', 'About text (blank line = new paragraph)', 'area', "Tharidu K. Wijekoon is a science graduate in Chemistry and Physics who teaches G.C.E. A/L Chemistry as Genuine Chemistry. Every topic is built from first principles, then turned into exam-ready answers through regular paper practice and fast online revision."],
            ['about_badge1', 'Photo badge – name', 'text', 'Tharidu K. Wijekoon'], ['about_badge2', 'Photo badge – small', 'text', 'BSc (Chem, Phy)'],
            ['qual', 'Highlights', 'list', [['icon', 'Icon'], ['title', 'Title'], ['sub', 'Small text']], [
                ['icon' => 'graduation-cap', 'title' => 'BSc (Chem, Phy)', 'sub' => 'Chemistry & Physics graduate'], ['icon' => 'atom', 'title' => 'Full A/L syllabus', 'sub' => 'Physical, inorganic & organic'],
                ['icon' => 'clipboard-check', 'title' => 'Paper practice', 'sub' => 'MCQ, structured & essay'], ['icon' => 'monitor-play', 'title' => 'Online revision', 'sub' => 'Learn from anywhere']]],
            ['about_btn', 'Button text', 'text', 'Contact Sir'],
        ]],
        'classes' => ['Classes section (three boxes)', [
            ['c_tag', 'Tag', 'text', 'Our classes'], ['c_t1', 'Title – start', 'text', 'Three ways to master'], ['c_t2', 'Title – blue part', 'text', 'A/L Chemistry'],
            ['c_lead', 'Sub text', 'text', 'Combine theory with paper classes and online revision for complete preparation.'],
            ['cards', 'Boxes', 'list', [['icon', 'Icon'], ['title', 'Title'], ['si', 'Sinhala title'], ['text', 'Description'], ['places', 'Places (comma separated)']], [
                ['icon' => 'book-open', 'title' => 'Theory', 'si' => 'න්‍යාය පන්ති', 'text' => 'Complete syllabus coverage with clear concepts, reactions and calculations explained step by step.', 'places' => 'Senara – Kegalle'],
                ['icon' => 'file-text', 'title' => 'Paper class', 'si' => 'ප්‍රශ්න පත්‍ර පන්ති', 'text' => 'Full-day paper sessions with discussion, so you learn timing, marking and answer technique.', 'places' => 'Senara – Kegalle, Sithumina – Kurunegala'],
                ['icon' => 'laptop', 'title' => 'Online speed revision', 'si' => 'ඔන්ලයින් වේගවත් පුනරීක්ෂණ', 'text' => 'Fast-paced revision of the whole syllabus from home — ideal for the final months before the exam.', 'places' => 'Online']]],
        ]],
        'offer' => ['What we offer (tiles)', [
            ['o_tag', 'Tag', 'text', 'What we offer'], ['o_t1', 'Title – start', 'text', 'Everything you need to'], ['o_t2', 'Title – red word', 'text', 'score'],
            ['tiles', 'Tiles', 'list', [['icon', 'Icon'], ['si', 'Sinhala text'], ['sub', 'English text']], [
                ['icon' => 'lightbulb', 'si' => 'සංකල්ප මූලිකව ඉගැන්වීම', 'sub' => 'Concept-first teaching'], ['icon' => 'clipboard-list', 'si' => 'නිතිපතා ප්‍රශ්න පත්‍ර', 'sub' => 'Regular paper classes'],
                ['icon' => 'monitor-play', 'si' => 'ඔන්ලයින් පුනරීක්ෂණ', 'sub' => 'Online speed revision'], ['icon' => 'headset', 'si' => 'සෘජු සහාය', 'sub' => 'Direct WhatsApp support']]],
        ]],
        'why' => ['Why section', [
            ['w_tag', 'Tag', 'text', 'Why Genuine Chemistry'], ['w_t1', 'Title – start', 'text', 'Built for'], ['w_t2', 'Title – blue word', 'text', 'results'], ['w_t3', 'Title – end', 'text', ', not memorising'],
            ['w_caption', 'Photo caption', 'text', 'From the lab bench to the answer sheet.'], ['w_btn', 'Button text', 'text', 'Enroll now'],
            ['feat', 'Features', 'list', [['icon', 'Icon'], ['title', 'Title'], ['text', 'Description']], [
                ['icon' => 'brain', 'title' => 'Concepts first', 'text' => "Understand why reactions happen, so you can answer questions you've never seen."],
                ['icon' => 'timer', 'title' => 'Regular paper practice', 'text' => 'Full-day paper classes train speed, accuracy and marking-scheme thinking.'],
                ['icon' => 'house', 'title' => 'Revision from home', 'text' => 'Evening online sessions cover the syllabus quickly when exam time is close.'],
                ['icon' => 'message-circle', 'title' => 'Direct support', 'text' => 'Ask questions and get class updates directly on WhatsApp.']]],
        ]],
        'community' => ['WhatsApp community box', [
            ['j_title', 'Title', 'text', 'Join the WhatsApp community'], ['j_si', 'Sinhala line', 'text', 'පන්ති තොරතුරු මුලින්ම ඔබට'],
            ['j_text', 'Text', 'text', 'Class updates, paper notices and announcements — straight to your phone.'],
        ]],
        'contact' => ['Contact section', [
            ['k_tag', 'Tag', 'text', 'Contact'], ['k_t1', 'Title – start', 'text', 'Reserve your'], ['k_t2', 'Title – red word', 'text', 'seat'],
            ['k_lead', 'Sinhala sub text', 'text', 'සියලුම විමසීම් සඳහා අමතන්න හෝ WhatsApp කරන්න.'],
            ['places', 'Address boxes (map link optional)', 'list', [['title', 'Small label'], ['text', 'Name'], ['url', 'Map / link']], [
                ['title' => 'Kegalle', 'text' => 'සෙනාරා – කෑගල්ල', 'url' => 'https://www.google.com/maps/search/Senara+Institute+Kegalle'],
                ['title' => 'Kurunegala', 'text' => 'සිතුමිණ – කුරුණෑගල', 'url' => 'https://www.google.com/maps/search/Sithumina+Institute+Kurunegala']]],
        ]],
        'footer' => ['Footer', [
            ['f_si', 'Footer sentence', 'text', 'රසායන විද්‍යාව — නියම විදිහට. G.C.E. A/L Chemistry with Tharidu K. Wijekoon.'], ['f_sub', 'Under the logo', 'text', 'Genuine Chemistry · BSc (Chem, Phy)'],
            ['f_rights', 'Right side text', 'text', 'All rights reserved'],
        ]],
    ];
}
function home_defaults(): array {
    $d = [];
    foreach (home_schema() as $sec) foreach ($sec[1] as $f) $d[$f[0]] = $f[2] === 'list' ? $f[4] : $f[3];
    return $d;
}
function home(): array {
    static $h;
    if ($h === null) {
        $h = home_defaults();
        $j = json_decode(setting('home_json', '{}'), true);
        if (is_array($j)) foreach ($j as $k => $v) if (array_key_exists($k, $h)) $h[$k] = $v;
        // legacy settings from v1
        if (!isset($j['about_text']) && setting('about_text') !== '') $h['about_text'] = setting('about_text');
        if (!isset($j['about_title']) && setting('about_title') !== '') $h['about_title'] = setting('about_title');
    }
    return $h;
}
