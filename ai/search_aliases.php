<?php
// Search-term alias/synonym dictionary for ai_search_products(). Kept small
// and evidence-based on purpose - do not grow this into hundreds of guessed
// entries. Extend it when a REAL zero-result search is found (e.g. by
// mining ai_conversation for user turns whose tool replies came back
// empty), the same way this seed entry was found.

// Arabic/Darija words -> their French catalog equivalent(s). Checked
// BEFORE accent-folding (transliteration isn't an accent issue). This
// matters more than it looks: reference.reference and pvd.description are
// latin1-encoded and cannot even store Arabic characters, so an Arabic
// term searched there is silently lost - translating to French before the
// database call is the only way these terms can ever match at all.
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
