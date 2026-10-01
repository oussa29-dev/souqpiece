<?php
// Phonetic matching of Darija part names written in Arabic letters against
// the French words of the catalog. Most of them are the French word itself,
// spelled by ear (store owner's observation, 01/10): روتيل = ROTTUL,
// كاردن = CARDON, ديمارور = DEMAREUR, الترناتور = ALTERNATEUR,
// فيزيبل = FUSIBLE. Both sides are reduced to a consonant skeleton (Arabic
// script rarely writes short vowels, so vowels are dropped on both sides)
// and compared. Only used for Arabic-script words the dictionary
// (search_aliases.php) does not know; true synonyms (مارش = démarreur) still
// need the dictionary.

// Latin word -> its keys, as variants (a French final consonant or final
// "e" is often silent: ROULEMENT ~ رولمان, CULASSE ~ كيلاس):
//   'sq'  consonant skeleton, used to find candidates
//   'voc' same with vowels reduced to A / I (e, i, y) / U (o, u, ou, eu),
//         used to choose between candidates - Arabic spelling keeps long
//         vowels (ا ي و), which separates FILTER from FLOTTEUR (both FLTR).
function ai_cles_latin(string $mot): array
{
    $m = strtoupper(strtr($mot, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 's', 'À' => 'A', 'Â' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Î' => 'I',
        'Ô' => 'O', 'Û' => 'U', 'Ç' => 'S',
    ]));
    $m = preg_replace('/[^A-Z]/', '', $m);
    // Multi-letter sounds first, then context-dependent C and G.
    $m = strtr($m, ['CH' => 'X', 'SH' => 'X', 'PH' => 'F', 'QU' => 'K', 'CK' => 'K']);
    $m = preg_replace('/GU(?=[EIY])/', 'K', $m);
    $m = preg_replace('/C(?=[EIY])/', 'S', $m);
    $m = preg_replace('/G(?=[EIY])/', 'J', $m);
    $m = strtr($m, ['C' => 'K', 'Q' => 'K', 'G' => 'K', 'P' => 'B', 'V' => 'F', 'Z' => 'S', 'W' => '', 'H' => '']);

    $formes = [$m];
    if (strlen($m) > 3 && preg_match('/[TDSX]$/', $m)) {
        $formes[] = substr($m, 0, -1);
    }
    foreach ($formes as $f) {
        if (strlen($f) > 3 && substr($f, -1) === 'E') {
            $formes[] = substr($f, 0, -1);
        }
    }

    $cles = ['sq' => [], 'voc' => []];
    foreach (array_unique($formes) as $f) {
        $cles['sq'][] = preg_replace('/(.)\1+/', '$1', preg_replace('/[AEIOUY]/', '', $f));
        $v = strtr($f, ['EAU' => 'U', 'OU' => 'U', 'AU' => 'U', 'EU' => 'U', 'OI' => 'UA', 'AI' => 'I', 'EI' => 'I']);
        $v = strtr($v, ['E' => 'I', 'Y' => 'I', 'O' => 'U']);
        $cles['voc'][] = preg_replace('/(.)\1+/', '$1', $v);
    }
    $cles['sq'] = array_values(array_unique($cles['sq']));
    $cles['voc'] = array_values(array_unique($cles['voc']));
    return $cles;
}

// Arabic-script word -> ['sq' => consonant skeleton, 'voc' => with the
// long vowels ا (A) ي (I) و (U)], same alphabet as ai_cles_latin().
function ai_cles_arabe(string $mot): array
{
    static $carte = [
        'ب' => 'B', 'پ' => 'B', 'ت' => 'T', 'ط' => 'T', 'ث' => 'T', 'ج' => 'J', 'چ' => 'X',
        'خ' => 'K', 'د' => 'D', 'ض' => 'D', 'ذ' => 'D', 'ر' => 'R', 'ز' => 'S', 'س' => 'S',
        'ص' => 'S', 'ش' => 'X', 'ف' => 'F', 'ڤ' => 'F', 'ق' => 'K', 'ڨ' => 'K', 'ك' => 'K',
        'گ' => 'K', 'غ' => 'K', 'ل' => 'L', 'م' => 'M', 'ن' => 'N',
        'ا' => 'A', 'أ' => 'A', 'إ' => 'A', 'آ' => 'A', 'ة' => 'A', 'و' => 'U', 'ي' => 'I', 'ى' => 'I',
    ];
    $v = '';
    foreach (preg_split('//u', $mot, -1, PREG_SPLIT_NO_EMPTY) as $lettre) {
        $v .= $carte[$lettre] ?? '';
    }
    $v = preg_replace('/(.)\1+/', '$1', $v);
    return [
        'sq' => preg_replace('/(.)\1+/', '$1', preg_replace('/[AIU]/', '', $v)),
        'voc' => $v,
    ];
}

// Everyday words of a request, never part names - matched by sound they
// produced false hits (بغيت "I want" -> BAGUETTE, الثمن "the price" ->
// LAMANE).
function ai_mots_courants_arabes(): array
{
    return ['بغيت', 'نحب', 'حاب', 'عندكم', 'عندك', 'كاين', 'كاش', 'واش', 'السعر', 'الثمن', 'سعر', 'ثمن', 'بشحال',
        'شحال', 'تاع', 'ديال', 'متاع', 'سيارة', 'طوموبيل', 'لوطو', 'محرك', 'قطعة', 'قطع', 'غيار', 'من', 'في', 'على',
        'هل', 'لديكم', 'متوفر', 'نتاع', 'ليا', 'لي', 'هذي', 'هاذي', 'سلام', 'شكرا', 'صحيت', 'مرحبا'];
}

function ai_est_arabe(string $mot): bool
{
    return preg_match('/\p{Arabic}/u', $mot) === 1;
}

// Catalog vocabulary: every alphabetic word of 3+ letters in a priced
// product name, with its skeleton(s). Built once per request, only when an
// unknown Arabic word actually needs it.
function ai_vocabulaire_catalogue(PDO $pdo): array
{
    static $vocab = null;
    if ($vocab !== null) {
        return $vocab;
    }
    $mots = [];
    foreach ($pdo->query('SELECT libelle FROM produit WHERE prix > 0')->fetchAll(PDO::FETCH_COLUMN) as $libelle) {
        foreach (preg_split('/[^A-Za-zÀ-ÿ]+/u', (string)$libelle) as $mot) {
            if (strlen($mot) >= 3) {
                $mots[strtoupper($mot)] = ($mots[strtoupper($mot)] ?? 0) + 1;
            }
        }
    }
    $vocab = [];
    foreach ($mots as $mot => $frequence) {
        $vocab[$mot] = ai_cles_latin($mot) + ['n' => $frequence];
    }
    return $vocab;
}

// Number of differing positions of two equal-length strings.
function ai_ecarts_meme_longueur(string $a, string $b): int
{
    $n = 0;
    for ($i = 0, $l = strlen($a); $i < $l; $i++) {
        if ($a[$i] !== $b[$i]) {
            $n++;
        }
    }
    return $n;
}

// Catalog words that sound like this Arabic-script word (at most 3, best
// vowel match first). Rules, each from a measured false hit:
// - same first sound (ماستر matched COASTER);
// - same skeleton length, substitutions only (ماستر matched MOTEUR by a
//   dropped letter); up to 1 substitution for 4-5 consonants, 2 for 6+;
// - under 3 consonants nothing (SB is both SOUPAPE and SABO); at exactly 3,
//   the long vowels must also agree (ريترو matched ROTOR / RETOUR);
// - among candidates, only the closest vowel pattern is kept (فيلتر is
//   FILTER, not FLOTTEUR).
function ai_correspondances_phonetiques(PDO $pdo, string $motArabe): array
{
    $mot = trim($motArabe);
    if (in_array($mot, ai_mots_courants_arabes(), true)) {
        return [];
    }
    $cles = [ai_cles_arabe($mot)];
    // A leading "ال" can be the article (الفرامل) or part of the word
    // (الترناتور = ALTERNATEUR): try both.
    if (mb_substr($mot, 0, 2) === 'ال') {
        $cles[] = ai_cles_arabe(mb_substr($mot, 2));
    }

    $distances = [];
    foreach ($cles as $cle) {
        $sq = $cle['sq'];
        $n = strlen($sq);
        if ($n < 3) {
            continue;
        }
        $tolerance = $n <= 3 ? 0 : ($n <= 5 ? 1 : 2);
        foreach (ai_vocabulaire_catalogue($pdo) as $motCatalogue => $clesLatin) {
            $ecart = null;
            foreach ($clesLatin['sq'] as $sc) {
                if (strlen($sc) === $n && $sc[0] === $sq[0]) {
                    $e = ai_ecarts_meme_longueur($sq, $sc);
                    if ($e <= $tolerance && ($ecart === null || $e < $ecart)) {
                        $ecart = $e;
                    }
                }
            }
            if ($ecart === null) {
                continue;
            }
            $dv = min(array_map(fn($v) => levenshtein($cle['voc'], $v), $clesLatin['voc']));
            if ($n === 3 && $dv > 1) {
                continue;
            }
            if (!isset($distances[$motCatalogue]) || [$ecart, $dv] < $distances[$motCatalogue]) {
                $distances[$motCatalogue] = [$ecart, $dv];
            }
        }
    }
    if ($distances === []) {
        return [];
    }
    // 1. Exact consonants beat a substituted one: with CARDON present,
    //    CARBON is noise (other spellings like CARDAN or TRIANGEL still come
    //    through the spelling groups of search_aliases.php).
    $minEcart = min(array_column($distances, 0));
    $distances = array_filter($distances, fn($d) => $d[0] === $minEcart);
    // 2. Closest vowel pattern plus near-ties: the closest spelling is often
    //    a rare typo of the catalog (ROULMENT) while the common word
    //    (ROULEMENT, 595 products) is one vowel away - most used first.
    $minVoyelles = min(array_column($distances, 1));
    $vocab = ai_vocabulaire_catalogue($pdo);
    $retenus = array_keys(array_filter($distances, fn($d) => $d[1] <= $minVoyelles + 1));
    usort($retenus, fn($a, $b) => $vocab[$b]['n'] <=> $vocab[$a]['n']);
    return array_slice(array_map('strtolower', $retenus), 0, 3);
}
