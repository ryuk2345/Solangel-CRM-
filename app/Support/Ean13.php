<?php

namespace App\Support;

/**
 * Códigos de barras EAN-13 internos de la tienda.
 * Usa el prefijo 2xx (reservado por GS1 para uso interno en tiendas), así no choca
 * con los códigos de fábrica. Formato: "20" + 10 dígitos del id de la variante + dígito verificador.
 */
class Ean13
{
    public static function forVariant(int $variantId): string
    {
        $body = '20' . str_pad((string) $variantId, 10, '0', STR_PAD_LEFT);

        return $body . self::checkDigit($body);
    }

    public static function checkDigit(string $twelveDigits): int
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function isValid(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code)
            && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /**
     * Patrón de barras EAN-13 (95 módulos: '1' = barra, '0' = espacio) para dibujar la etiqueta.
     */
    public static function pattern(string $code): string
    {
        $L = ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'];
        $G = ['0100111','0110011','0011011','0100001','0011101','0111001','0000101','0010001','0001001','0010111'];
        $R = ['1110010','1100110','1101100','1000010','1011100','1001110','1010000','1000100','1001000','1110100'];
        $parity = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];

        $digits = array_map('intval', str_split($code));
        $first = array_shift($digits);
        $bits = '101';
        for ($i = 0; $i < 6; $i++) {
            $bits .= $parity[$first][$i] === 'L' ? $L[$digits[$i]] : $G[$digits[$i]];
        }
        $bits .= '01010';
        for ($i = 6; $i < 12; $i++) {
            $bits .= $R[$digits[$i]];
        }

        return $bits . '101';
    }

    /**
     * Código de barras como imagen PNG (data URI) para las etiquetas en PDF.
     * Cada módulo mide $module píxeles; incluye 9 módulos de margen a cada lado.
     */
    public static function pngDataUri(string $code, int $module = 4, int $height = 120): string
    {
        $bits = self::pattern($code);
        $quiet = 9;
        $width = (strlen($bits) + $quiet * 2) * $module;

        $img = imagecreate($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);

        foreach (str_split($bits) as $i => $bit) {
            if ($bit === '1') {
                $x = ($quiet + $i) * $module;
                imagefilledrectangle($img, $x, 0, $x + $module - 1, $height - 1, $black);
            }
        }

        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
