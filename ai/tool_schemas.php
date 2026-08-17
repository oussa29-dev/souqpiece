<?php
// Generic JSON-Schema tool definitions - standard lowercase types, no
// vendor-specific formatting. Each provider adapter is responsible for
// translating this into its own wire format (see GeminiProvider::toGeminiTypes).
// The functions these describe live in ai/tools.php and are never modified
// to fit a provider - only this description layer + the dispatcher below.

function ai_tool_schemas(): array
{
    return [
        [
            'name' => 'search_products',
            'description' => 'Search the parts catalog by free text (French part names, descriptions, or OEM reference numbers). Returns matching products with price, stock and a direct product page URL.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search text, e.g. a part name or reference number.'],
                    'id_voiture' => ['type' => 'integer', 'description' => 'Optional: restrict results to this vehicle id (from resolve_vehicle).'],
                    'id_sous_categorie' => ['type' => 'integer', 'description' => 'Optional: restrict results to this subcategory id.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results to return, default 8, max 20.'],
                    'min_price' => ['type' => 'integer', 'description' => 'Optional: only return products priced at or above this amount (DZD).'],
                    'max_price' => ['type' => 'integer', 'description' => 'Optional: only return products priced at or below this amount (DZD). When set (with or without min_price), results are sorted cheapest first instead of the default stock-first order.'],
                ],
                'required' => ['query'],
            ],
        ],
        [
            'name' => 'lookup_by_reference',
            'description' => 'Look up an exact OEM/manufacturer reference number. Returns all matching products grouped by brand (marquepiece), since the same reference number is often sold under several different brands at different prices.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'description' => 'The reference number, e.g. "11311-54052".'],
                ],
                'required' => ['reference'],
            ],
        ],
        [
            'name' => 'resolve_vehicle',
            'description' => 'Resolve free-text vehicle mentions (brand and/or model, in French or transliterated Arabic) to an id_voiture usable by other tools. Returns {unique: bool, matches: [up to 5 ranked candidates]}. `unique` is the deterministic signal for whether the customer needs to be asked to specify the exact model - see rule 9 in your instructions, it is not a judgment call.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'free_text' => ['type' => 'string', 'description' => 'What the customer said about their vehicle, e.g. "قاشقاي" or "toyota hilux".'],
                ],
                'required' => ['free_text'],
            ],
        ],
        [
            'name' => 'get_product',
            'description' => 'Get full detail for one specific product: price, stock, brand, category, fitment description, and product page URL.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id_produit' => ['type' => 'integer', 'description' => 'The product id.'],
                    'id_voiture' => ['type' => 'integer', 'description' => 'Optional: the vehicle id, to get the fitment description specific to that vehicle.'],
                ],
                'required' => ['id_produit'],
            ],
        ],
        [
            'name' => 'list_categories',
            'description' => 'List product categories and subcategories, optionally restricted to those that actually have products for a given vehicle. Useful when the customer\'s request is vague and needs narrowing down.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'id_voiture' => ['type' => 'integer', 'description' => 'Optional: restrict to categories with available products for this vehicle.'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_delivery_price',
            'description' => 'Get the delivery price for a given wilaya (Algerian province) and delivery mode.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'wilaya' => ['type' => 'string', 'description' => 'Wilaya name, e.g. "Alger", "Oran".'],
                    'mode' => ['type' => 'string', 'description' => 'Either "domicile" (home delivery) or "bureau" (pickup point).'],
                ],
                'required' => ['wilaya', 'mode'],
            ],
        ],
    ];
}

// Maps a tool name + raw args (as decoded from the model's function call)
// to an actual call into ai/tools.php, with basic type coercion.
function ai_build_tool_dispatcher(PDO $pdo): callable
{
    return function (string $name, array $args) use ($pdo): array {
        switch ($name) {
            case 'search_products':
                return ai_search_products(
                    $pdo,
                    (string)($args['query'] ?? ''),
                    isset($args['id_voiture']) ? (int)$args['id_voiture'] : null,
                    isset($args['id_sous_categorie']) ? (int)$args['id_sous_categorie'] : null,
                    isset($args['limit']) ? (int)$args['limit'] : 8,
                    isset($args['min_price']) ? (int)$args['min_price'] : null,
                    isset($args['max_price']) ? (int)$args['max_price'] : null
                );
            case 'lookup_by_reference':
                return ai_lookup_by_reference($pdo, (string)($args['reference'] ?? ''));
            case 'resolve_vehicle':
                return ai_resolve_vehicle($pdo, (string)($args['free_text'] ?? ''));
            case 'get_product':
                $r = ai_get_product(
                    $pdo,
                    (int)($args['id_produit'] ?? 0),
                    isset($args['id_voiture']) ? (int)$args['id_voiture'] : null
                );
                return $r ?? ['found' => false];
            case 'list_categories':
                return ai_list_categories($pdo, isset($args['id_voiture']) ? (int)$args['id_voiture'] : null);
            case 'get_delivery_price':
                $r = ai_get_delivery_price($pdo, (string)($args['wilaya'] ?? ''), (string)($args['mode'] ?? 'domicile'));
                return $r ?? ['found' => false];
            default:
                return ['error' => "unknown tool: $name"];
        }
    };
}
