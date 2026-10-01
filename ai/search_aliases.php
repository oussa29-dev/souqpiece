<?php
// Search-term alias/synonym dictionary for ai_search_products() and
// ai_resolve_vehicle(). Evidence-based only: every catalog spelling below
// was counted in produit.libelle on 2026-10-01 (e.g. FILTER 700 vs FILTRE 5,
// VILBROKEN 129, MAYEAU 339, PLATAUX 12), and every customer wording comes
// from a real ai_conversation turn or is the correct French word for one of
// those catalog spellings. Do not grow this with guessed entries - extend it
// when ai/eval_search.php or a logged conversation shows a real miss.

// Arabic/Darija words -> their French/catalog equivalent(s). One-way: an
// Arabic word expands to French, never the reverse. Checked BEFORE
// accent-folding; a leading "ال" is stripped by the caller when the word
// itself isn't listed. Vehicle names are here too so that both the text
// search (pvd.description contains the model name) and ai_resolve_vehicle()
// understand them.
function ai_search_arabic_aliases(): array
{
    return [
        // Parts
        'ديمارور' => ['demarreur', 'demareur'],
        'كيلاس' => ['culasse'],
        'كولاس' => ['culasse'],
        'فرامل' => ['frein', 'frien'],
        'فران' => ['frein', 'frien'],
        'روتروفيزور' => ['retroviseur', 'retrovisseur'],
        'ريتروفيزور' => ['retroviseur', 'retrovisseur'],
        'فيلتر' => ['filter', 'filtre'],
        'فلتر' => ['filter', 'filtre'],
        'امبرياج' => ['embrayage'],
        'انبرياج' => ['embrayage'],
        'بلاكيت' => ['plaquette'],
        'ديسك' => ['disque'],
        'رادياتور' => ['radiateur', 'radaiteur'],
        'امورتيسور' => ['amortisseur'],
        'فيلبروكان' => ['vilbroken', 'vilbroquin', 'vilebrequin'],
        'بوجي' => ['bougie'],
        'كوروا' => ['courroie'],
        'الترناتور' => ['alternateur'],
        'روتيل' => ['rotule', 'rottul'],
        // Vehicles (catalog model/brand names)
        'ياريس' => ['yaris'],
        'كورولا' => ['corolla'],
        'كواستر' => ['coaster'],
        'باترول' => ['patrol'],
        'هيلوكس' => ['hilux'],
        'فيغو' => ['vigo'],
        'فيقو' => ['vigo'],
        'باجيرو' => ['pajero'],
        'تويوتا' => ['toyota'],
        'نيسان' => ['nissan'],
        'ميتسوبيشي' => ['mitsubishi'],
    ];
}

// Groups of interchangeable NORMALIZED (lowercase, accent-folded) spellings
// - see ai_normalize_term() in tools.php. Any member typed by a customer
// expands to the whole group. Only full words here, never abbreviations:
// an abbreviation in a group would also be added when the full word is
// typed ("dem" would pull in "DEMI MOTEUR" for "demarreur"). Abbreviations
// already work on their own, the search is a substring match.
function ai_search_synonym_groups(): array
{
    return [
        ['demarreur', 'demareur', 'starter'],
        ['filtre', 'filter'],
        ['vilebrequin', 'vilbrequin', 'vilbroken', 'vilbroquin', 'vilbroquen', 'vilbreqin'],
        ['culasse', 'culase'],
        ['embrayage', 'embryage', 'embriage'],
        ['plateau', 'plateaux', 'plataux'],
        ['radiateur', 'radaiteur'],
        ['retroviseur', 'retrovisseur'],
        ['triangle', 'triangel'],
        ['rotule', 'rottul', 'rotull'],
        ['moyeu', 'mayeau', 'mayeu'],
        ['cremaillere', 'cremailler', 'cremayeur'],
        ['ressort', 'ressour'],
        ['durite', 'derit'],
        ['manchon', 'monchon'],
        ['cardan', 'cardon'],
        ['soufflet', 'soufler', 'souflet'],
        ['ferodo', 'ferredo'],
        ['optique', 'obtique'],
        ['ampoule', 'ampo'],
        ['goujon', 'gougen', 'goujen'],
        ['butee', 'bute'],
        ['injecteur', 'injecter'],
        ['silentbloc', 'silent', 'selen'],
        ['amortisseur', 'amortiseur'],
        ['frein', 'frien'],
    ];
}
