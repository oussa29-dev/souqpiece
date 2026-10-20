<?php
// Phone numbers as stored in client_gros: international form, digits only,
// no "+" (213550123456) - exactly what the WhatsApp API gives as the sender,
// so recognising a wholesale customer is a plain equality. Shared by the
// dashboard page and, later, the WhatsApp channel.

// "0550 12 34 56", "+213 550-12-34-56", "00213550123456", "550123456" ->
// "213550123456". Foreign numbers given with + or 00 are kept as is.
// Returns null when the input is not a plausible number.
function telephone_normaliser(string $saisie): ?string
{
    $saisie = trim($saisie);
    $international = strpos($saisie, '+') === 0;
    $chiffres = preg_replace('/\D/', '', $saisie);
    if ($chiffres === '') {
        return null;
    }
    if (strpos($chiffres, '00') === 0) {
        $chiffres = substr($chiffres, 2);
        $international = true;
    }
    // Algerian mobile (0 + 9 digits) or landline (0 + 8 digits), or the
    // mobile's 9 digits alone.
    if (!$international && (strlen($chiffres) === 10 || strlen($chiffres) === 9) && $chiffres[0] === '0') {
        return '213' . substr($chiffres, 1);
    }
    if (!$international && strlen($chiffres) === 9 && strpos('5672', $chiffres[0]) !== false) {
        return '213' . $chiffres;
    }
    if (strpos($chiffres, '213') === 0) {
        return (strlen($chiffres) === 12 || strlen($chiffres) === 11) ? $chiffres : null;
    }
    if ($international && strlen($chiffres) >= 8 && strlen($chiffres) <= 15) {
        return $chiffres;
    }
    return null;
}

// "213550123456" -> "0550 12 34 56"; other countries -> "+<digits>".
function telephone_afficher(string $normalise): string
{
    if (strlen($normalise) === 11 && strpos($normalise, '213') === 0) {
        $local = '0' . substr($normalise, 3);
        return substr($local, 0, 3) . ' ' . substr($local, 3, 2) . ' ' . substr($local, 5, 2) . ' ' . substr($local, 7, 2);
    }
    if (strlen($normalise) === 12 && strpos($normalise, '213') === 0) {
        $local = '0' . substr($normalise, 3);
        return substr($local, 0, 4) . ' ' . substr($local, 4, 2) . ' ' . substr($local, 6, 2) . ' ' . substr($local, 8, 2);
    }
    return '+' . $normalise;
}
