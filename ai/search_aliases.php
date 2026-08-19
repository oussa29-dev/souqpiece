<?php
// Search-term alias/synonym dictionary for ai_search_products(). Kept small
// and evidence-based on purpose - do not grow this into hundreds of guessed
// entries. Extend it when a REAL zero-result search is found (e.g. by
// mining ai_conversation for user turns whose tool replies came back
// empty), the same way this seed entry was found.

// Arabic/Darija words -> their French catalog equivalent(s). Checked
// BEFORE accent-folding (transliteration isn't an accent issue). The
// catalog itself is French, so even now that every text column is
// utf8mb4 (searchable in Arabic), an Arabic term still only matches
// products whose libelle/description happens to contain Arabic text
// (rare) unless it's translated to French first - this dictionary is
// what makes those terms match at all.
function ai_search_arabic_aliases(): array
{
    return [
        'ديمارور' => ['demarreur', 'demareur'],
    ];
}

// Groups of interchangeable NORMALIZED (lowercase, accent-folded) terms -
// see ai_normalize_term() in tools.php. Verified via a real production
// conversation audit (2026-08-17): the catalog spells this word "DEMAREUR"
// (one R) in all 279 matching products and in the subcategory name itself,
// while customers naturally type the correct French spelling "démarreur"
// (two R). A literal substring search never matches either direction.
function ai_search_synonym_groups(): array
{
    return [
        ['demarreur', 'demareur', 'starter'],
    ];
}
