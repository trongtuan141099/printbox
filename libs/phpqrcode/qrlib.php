<?php
/**
 * PHP QR Code - Self-contained offline QR Code Generator
 * Compatible with PHP 7.4 - 8.3+
 * Zero external internet dependency.
 * Outputs PNG (via GD) or SVG (pure vector, zero GD dependency) or Base64 Data URI.
 */

if (!class_exists('QRcode')) {

class QRcode {
    const QR_ECLEVEL_L = 0; // ~7% error recovery
    const QR_ECLEVEL_M = 1; // ~15% error recovery
    const QR_ECLEVEL_Q = 2; // ~25% error recovery
    const QR_ECLEVEL_H = 3; // ~30% error recovery

    // Galois Field GF(2^8) tables
    private static $gfExp = null;
    private static $gfLog = null;

    // Version capacities for 8-bit byte mode (Level L, M, Q, H)
    private static $versionCapacities = [
        1 => [17, 14, 11, 7],
        2 => [32, 26, 20, 14],
        3 => [53, 42, 32, 24],
        4 => [78, 62, 46, 34],
        5 => [106, 84, 60, 44],
        6 => [134, 106, 74, 58],
        7 => [154, 122, 86, 64],
        8 => [192, 152, 108, 84],
        9 => [230, 180, 130, 98],
        10 => [271, 213, 151, 119],
        11 => [321, 251, 177, 137],
        12 => [367, 287, 203, 155],
        13 => [425, 331, 241, 177],
        14 => [458, 362, 258, 194],
    ];

    // Total data codewords & EC codewords per block table
    // [version => [level => ['ec_words_per_block' => x, 'blocks_g1' => b1, 'data_words_g1' => d1, 'blocks_g2' => b2, 'data_words_g2' => d2]]]
    private static $rsParams = [
        1 => [
            0 => ['ec' => 7, 'b1' => 1, 'd1' => 19, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 10, 'b1' => 1, 'd1' => 16, 'b2' => 0, 'd2' => 0],
            2 => ['ec' => 13, 'b1' => 1, 'd1' => 13, 'b2' => 0, 'd2' => 0],
            3 => ['ec' => 17, 'b1' => 1, 'd1' => 9, 'b2' => 0, 'd2' => 0],
        ],
        2 => [
            0 => ['ec' => 10, 'b1' => 1, 'd1' => 34, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 16, 'b1' => 1, 'd1' => 28, 'b2' => 0, 'd2' => 0],
            2 => ['ec' => 22, 'b1' => 1, 'd1' => 22, 'b2' => 0, 'd2' => 0],
            3 => ['ec' => 28, 'b1' => 1, 'd1' => 16, 'b2' => 0, 'd2' => 0],
        ],
        3 => [
            0 => ['ec' => 15, 'b1' => 1, 'd1' => 55, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 26, 'b1' => 1, 'd1' => 44, 'b2' => 0, 'd2' => 0],
            2 => ['ec' => 18, 'b1' => 2, 'd1' => 17, 'b2' => 0, 'd2' => 0],
            3 => ['ec' => 22, 'b1' => 2, 'd1' => 13, 'b2' => 0, 'd2' => 0],
        ],
        4 => [
            0 => ['ec' => 20, 'b1' => 1, 'd1' => 80, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 18, 'b1' => 2, 'd1' => 32, 'b2' => 0, 'd2' => 0],
            2 => ['ec' => 26, 'b1' => 2, 'd1' => 24, 'b2' => 0, 'd2' => 0],
            3 => ['ec' => 16, 'b1' => 4, 'd1' => 9, 'b2' => 0, 'd2' => 0],
        ],
        5 => [
            0 => ['ec' => 26, 'b1' => 1, 'd1' => 108, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 24, 'b1' => 2, 'd1' => 43, 'b2' => 0, 'd2' => 0],
            2 => ['ec' => 18, 'b1' => 2, 'd1' => 15, 'b2' => 2, 'd2' => 16],
            3 => ['ec' => 22, 'b1' => 2, 'd1' => 11, 'b2' => 2, 'd2' => 12],
        ],
        6 => [
            0 => ['ec' => 18, 'b1' => 2, 'd1' => 68, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 16, 'b1' => 4, 'd1' => 27, 'b2' => 0, 'd2' => 0],
            2 => ['ec' => 24, 'b1' => 4, 'd1' => 19, 'b2' => 0, 'd2' => 0],
            3 => ['ec' => 28, 'b1' => 4, 'd1' => 15, 'b2' => 0, 'd2' => 0],
        ],
        7 => [
            0 => ['ec' => 20, 'b1' => 2, 'd1' => 78, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 18, 'b1' => 4, 'd1' => 31, 'b2' => 0, 'd2' => 0],
            2 => ['ec' => 18, 'b1' => 2, 'd1' => 14, 'b2' => 4, 'd2' => 15],
            3 => ['ec' => 26, 'b1' => 4, 'd1' => 13, 'b2' => 1, 'd2' => 14],
        ],
        8 => [
            0 => ['ec' => 24, 'b1' => 2, 'd1' => 97, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 22, 'b1' => 2, 'd1' => 38, 'b2' => 2, 'd2' => 39],
            2 => ['ec' => 22, 'b1' => 4, 'd1' => 18, 'b2' => 2, 'd2' => 19],
            3 => ['ec' => 26, 'b1' => 4, 'd1' => 14, 'b2' => 2, 'd2' => 15],
        ],
        9 => [
            0 => ['ec' => 30, 'b1' => 2, 'd1' => 116, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 22, 'b1' => 3, 'd1' => 36, 'b2' => 2, 'd2' => 37],
            2 => ['ec' => 20, 'b1' => 4, 'd1' => 16, 'b2' => 4, 'd2' => 17],
            3 => ['ec' => 24, 'b1' => 4, 'd1' => 12, 'b2' => 4, 'd2' => 13],
        ],
        10 => [
            0 => ['ec' => 18, 'b1' => 2, 'd1' => 68, 'b2' => 2, 'd2' => 69],
            1 => ['ec' => 26, 'b1' => 4, 'd1' => 43, 'b2' => 1, 'd2' => 44],
            2 => ['ec' => 24, 'b1' => 6, 'd1' => 19, 'b2' => 2, 'd2' => 20],
            3 => ['ec' => 28, 'b1' => 6, 'd1' => 15, 'b2' => 2, 'd2' => 16],
        ],
        11 => [
            0 => ['ec' => 20, 'b1' => 4, 'd1' => 81, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 30, 'b1' => 1, 'd1' => 50, 'b2' => 4, 'd2' => 51],
            2 => ['ec' => 28, 'b1' => 4, 'd1' => 22, 'b2' => 4, 'd2' => 23],
            3 => ['ec' => 24, 'b1' => 3, 'd1' => 12, 'b2' => 8, 'd2' => 13],
        ],
        12 => [
            0 => ['ec' => 24, 'b1' => 2, 'd1' => 92, 'b2' => 2, 'd2' => 93],
            1 => ['ec' => 22, 'b1' => 6, 'd1' => 36, 'b2' => 2, 'd2' => 37],
            2 => ['ec' => 26, 'b1' => 4, 'd1' => 20, 'b2' => 6, 'd2' => 21],
            3 => ['ec' => 28, 'b1' => 7, 'd1' => 14, 'b2' => 4, 'd2' => 15],
        ],
        13 => [
            0 => ['ec' => 26, 'b1' => 4, 'd1' => 107, 'b2' => 0, 'd2' => 0],
            1 => ['ec' => 22, 'b1' => 8, 'd1' => 37, 'b2' => 1, 'd2' => 38],
            2 => ['ec' => 24, 'b1' => 8, 'd1' => 20, 'b2' => 4, 'd2' => 21],
            3 => ['ec' => 22, 'b1' => 12, 'd1' => 11, 'b2' => 4, 'd2' => 12],
        ],
        14 => [
            0 => ['ec' => 30, 'b1' => 3, 'd1' => 115, 'b2' => 1, 'd2' => 116],
            1 => ['ec' => 24, 'b1' => 4, 'd1' => 40, 'b2' => 5, 'd2' => 41],
            2 => ['ec' => 20, 'b1' => 11, 'd1' => 16, 'b2' => 5, 'd2' => 17],
            3 => ['ec' => 24, 'b1' => 11, 'd1' => 12, 'b2' => 5, 'd2' => 13],
        ],
    ];

    // Alignment patterns positions by version
    private static $alignmentPatterns = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 36, 66],
    ];

    // Format info bit sequences (precomputed BCH codes for 15 bits, masked with 0x5412)
    // [ec_level (0..3)][mask (0..7)]
    private static $formatInfo = [
        0 => [0x77c4, 0x72f3, 0x7daa, 0x789d, 0x662f, 0x6318, 0x6c41, 0x6976], // L
        1 => [0x5412, 0x5125, 0x5e7c, 0x5b4b, 0x45f9, 0x40ce, 0x4f97, 0x4aa0], // M
        2 => [0x355f, 0x3068, 0x3f31, 0x3a06, 0x24b4, 0x2183, 0x2eda, 0x2bed], // Q
        3 => [0x1689, 0x13be, 0x1ce7, 0x19d0, 0x0762, 0x0255, 0x0d0c, 0x083b], // H
    ];

    // Version info 18 bits for versions 7..14
    private static $versionInfo = [
        7 => 0x07c94,
        8 => 0x085bc,
        9 => 0x09a99,
        10 => 0x0a4d3,
        11 => 0x0bbf6,
        12 => 0x0c762,
        13 => 0x0d847,
        14 => 0x0e60d,
    ];

    private static function initGaloisField() {
        if (self::$gfExp !== null) return;
        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $x;
            self::$gfLog[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d; // Generator polynomial x^8 + x^4 + x^3 + x^2 + 1
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
    }

    private static function gfMul($x, $y) {
        if ($x == 0 || $y == 0) return 0;
        return self::$gfExp[self::$gfLog[$x] + self::$gfLog[$y]];
    }

    private static function rsGeneratorPoly($degree) {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = [1];
            $factor = self::$gfExp[$i];
            $newPoly = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $newPoly[$j] ^= self::gfMul($poly[$j], $factor);
                $newPoly[$j + 1] ^= $poly[$j];
            }
            $poly = $newPoly;
        }
        return array_reverse($poly);
    }

    private static function rsCalculateECC($data, $ecCount) {
        self::initGaloisField();
        $poly = self::rsGeneratorPoly($ecCount);
        $remainder = array_fill(0, $ecCount, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            for ($i = 0; $i < $ecCount; $i++) {
                $remainder[$i] ^= self::gfMul($poly[$i + 1], $factor);
            }
        }
        return $remainder;
    }

    /**
     * Determine minimum QR version needed for given data length and EC level
     */
    private static function getVersion($dataLen, $ecLevel) {
        foreach (self::$versionCapacities as $ver => $caps) {
            if ($dataLen <= $caps[$ecLevel]) {
                return $ver;
            }
        }
        throw new Exception("Data is too long for QR Code (Max version 14 supported in compact mode)");
    }

    /**
     * Encode raw string into bitstream using Byte Mode (8-bit) with ECI UTF-8 support
     */
    private static function encodeData($text, $version, $ecLevel) {
        $len = strlen($text);
        $bits = '';

        // Detect multi-byte UTF-8
        $hasUtf8 = false;
        for ($i = 0; $i < $len; $i++) {
            if (ord($text[$i]) >= 128) {
                $hasUtf8 = true;
                break;
            }
        }

        // Prepend ECI mode header for UTF-8 (ECI 26) so mobile phone cameras decode UTF-8 correctly
        if ($hasUtf8) {
            $bits .= '0111';     // ECI Mode Indicator
            $bits .= '00011010'; // ECI assignment value 26 = UTF-8
        }

        // Mode indicator: 0100 for Byte mode
        $bits .= '0100';

        // Character count indicator: 8 bits for versions 1..9, 16 bits for versions 10..14
        $countBits = ($version <= 9) ? 8 : 16;
        $bits .= str_pad(decbin($len), $countBits, '0', STR_PAD_LEFT);

        // Data bytes
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Total data capacity in bits
        $param = self::$rsParams[$version][$ecLevel];
        $totalDataCodewords = ($param['b1'] * $param['d1']) + ($param['b2'] * $param['d2']);
        $totalDataBits = $totalDataCodewords * 8;

        // Terminator (up to 4 zeros)
        $bitsNeeded = $totalDataBits - strlen($bits);
        if ($bitsNeeded > 0) {
            $terminatorLen = min(4, $bitsNeeded);
            $bits .= str_repeat('0', $terminatorLen);
        }

        // Pad to byte boundary (multiple of 8)
        $mod = strlen($bits) % 8;
        if ($mod != 0) {
            $bits .= str_repeat('0', 8 - $mod);
        }

        // Pad bytes (0xEC, 0x11 alternating)
        $padBytes = [0xEC, 0x11];
        $padIndex = 0;
        while (strlen($bits) < $totalDataBits) {
            $bits .= str_pad(decbin($padBytes[$padIndex]), 8, '0', STR_PAD_LEFT);
            $padIndex = 1 - $padIndex;
        }

        // Convert bit string to byte array
        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        // Split data into blocks & calculate RS ECC
        $b1 = $param['b1'];
        $d1 = $param['d1'];
        $b2 = $param['b2'];
        $d2 = $param['d2'];
        $ecCount = $param['ec'];

        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        for ($i = 0; $i < $b1; $i++) {
            $block = array_slice($codewords, $offset, $d1);
            $dataBlocks[] = $block;
            $ecBlocks[] = self::rsCalculateECC($block, $ecCount);
            $offset += $d1;
        }
        for ($i = 0; $i < $b2; $i++) {
            $block = array_slice($codewords, $offset, $d2);
            $dataBlocks[] = $block;
            $ecBlocks[] = self::rsCalculateECC($block, $ecCount);
            $offset += $d2;
        }

        // Interleave data codewords
        $finalCodewords = [];
        $maxDataLen = max($d1, $d2);
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $block) {
                if ($i < count($block)) {
                    $finalCodewords[] = $block[$i];
                }
            }
        }

        // Interleave EC codewords
        for ($i = 0; $i < $ecCount; $i++) {
            foreach ($ecBlocks as $block) {
                if ($i < count($block)) {
                    $finalCodewords[] = $block[$i];
                }
            }
        }

        // Convert back to final bitstream
        $finalBits = '';
        foreach ($finalCodewords as $cw) {
            $finalBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        // Remainder bits for version
        $remainderBitsCount = [
            1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
            7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0,
            13 => 0, 14 => 3
        ][$version] ?? 0;

        if ($remainderBitsCount > 0) {
            $finalBits .= str_repeat('0', $remainderBitsCount);
        }

        return $finalBits;
    }

    /**
     * Build the raw QR Matrix (size = 17 + version * 4)
     */
    private static function buildMatrix($text, $ecLevel = self::QR_ECLEVEL_M) {
        $len = strlen($text);
        $hasUtf8 = false;
        for ($i = 0; $i < $len; $i++) {
            if (ord($text[$i]) >= 128) {
                $hasUtf8 = true;
                break;
            }
        }
        $requiredLen = $hasUtf8 ? ($len + 2) : $len;
        $version = self::getVersion($requiredLen, $ecLevel);
        $size = 17 + ($version * 4);

        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // 1. Finder patterns
        $setFinder = function($r0, $c0) use (&$matrix, &$reserved) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    $val = ($r == 0 || $r == 6 || $c == 0 || $c == 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) ? 1 : 0;
                    $matrix[$r0 + $r][$c0 + $c] = $val;
                    $reserved[$r0 + $r][$c0 + $c] = true;
                }
            }
        };

        // Top-left, Top-right, Bottom-left
        $setFinder(0, 0);
        $setFinder(0, $size - 7);
        $setFinder($size - 7, 0);

        // Separators around finder patterns
        $setSeparator = function($r0, $c0, $w, $h) use (&$matrix, &$reserved, $size) {
            for ($r = 0; $r < $h; $r++) {
                for ($c = 0; $c < $w; $c++) {
                    $rr = $r0 + $r;
                    $cc = $c0 + $c;
                    if ($rr >= 0 && $rr < $size && $cc >= 0 && $cc < $size) {
                        if ($matrix[$rr][$cc] === null) {
                            $matrix[$rr][$cc] = 0;
                            $reserved[$rr][$cc] = true;
                        }
                    }
                }
            }
        };

        $setSeparator(0, 7, 1, 8);
        $setSeparator(7, 0, 8, 1);
        $setSeparator(0, $size - 8, 1, 8);
        $setSeparator(7, $size - 8, 8, 1);
        $setSeparator($size - 8, 0, 8, 1);
        $setSeparator($size - 8, 7, 1, 8);

        // 2. Alignment patterns
        $alignCoords = self::$alignmentPatterns[$version];
        foreach ($alignCoords as $r) {
            foreach ($alignCoords as $c) {
                // Skip if overlapping finder patterns
                if (($r < 9 && $c < 9) || ($r < 9 && $c >= $size - 9) || ($r >= $size - 9 && $c < 9)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $val = (abs($dr) == 2 || abs($dc) == 2 || ($dr == 0 && $dc == 0)) ? 1 : 0;
                        $matrix[$r + $dr][$c + $dc] = $val;
                        $reserved[$r + $dr][$c + $dc] = true;
                    }
                }
            }
        }

        // 3. Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            if ($matrix[6][$i] === null) {
                $matrix[6][$i] = ($i % 2 == 0) ? 1 : 0;
                $reserved[6][$i] = true;
            }
            if ($matrix[$i][6] === null) {
                $matrix[$i][6] = ($i % 2 == 0) ? 1 : 0;
                $reserved[$i][6] = true;
            }
        }

        // Dark module
        $matrix[(4 * $version) + 9][8] = 1;
        $reserved[(4 * $version) + 9][8] = true;

        // Reserve format info areas
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }

        // Reserve version info areas (Version >= 7)
        if ($version >= 7) {
            for ($r = 0; $r < 6; $r++) {
                for ($c = 0; $c < 3; $c++) {
                    $reserved[$size - 11 + $c][$r] = true;
                    $reserved[$r][$size - 11 + $c] = true;
                }
            }
        }

        // 4. Place Data Bits in Zigzag
        $bits = self::encodeData($text, $version, $ecLevel);
        $bitIdx = 0;
        $bitLen = strlen($bits);

        $dir = -1; // Going up
        $r = $size - 1;
        $c = $size - 1;

        while ($c > 0) {
            if ($c == 6) $c--; // Skip vertical timing column

            while ($r >= 0 && $r < $size) {
                for ($colOffset = 0; $colOffset < 2; $colOffset++) {
                    $currC = $c - $colOffset;
                    if (!$reserved[$r][$currC]) {
                        $matrix[$r][$currC] = ($bitIdx < $bitLen) ? ($bits[$bitIdx] === '1' ? 1 : 0) : 0;
                        $bitIdx++;
                    }
                }
                $r += $dir;
            }

            $dir = -$dir;
            $r += $dir;
            $c -= 2;
        }

        // 5. Select Best Mask (Evaluate Penalty)
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        $maskedMatrix = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $testMatrix = $matrix;
            for ($row = 0; $row < $size; $row++) {
                for ($col = 0; $col < $size; $col++) {
                    if (!$reserved[$row][$col]) {
                        $invert = false;
                        switch ($mask) {
                            case 0: $invert = (($row + $col) % 2 == 0); break;
                            case 1: $invert = ($row % 2 == 0); break;
                            case 2: $invert = ($col % 3 == 0); break;
                            case 3: $invert = (($row + $col) % 3 == 0); break;
                            case 4: $invert = (((int)($row / 2) + (int)($col / 3)) % 2 == 0); break;
                            case 5: $invert = ((($row * $col) % 2) + (($row * $col) % 3) == 0); break;
                            case 6: $invert = (((($row * $col) % 2) + (($row * $col) % 3)) % 2 == 0); break;
                            case 7: $invert = (((($row + $col) % 2) + (($row * $col) % 3)) % 2 == 0); break;
                        }
                        if ($invert) {
                            $testMatrix[$row][$col] ^= 1;
                        }
                    }
                }
            }

            // Write format info to evaluate
            self::applyFormatInfo($testMatrix, $ecLevel, $mask, $size);
            if ($version >= 7) {
                self::applyVersionInfo($testMatrix, $version, $size);
            }

            $score = self::calculatePenalty($testMatrix, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
                $maskedMatrix = $testMatrix;
            }
        }

        return $maskedMatrix;
    }

    private static function applyFormatInfo(&$matrix, $ecLevel, $mask, $size) {
        $formatBits = self::$formatInfo[$ecLevel][$mask];
        // 15 bits
        $bits = [];
        for ($i = 0; $i < 15; $i++) {
            $bits[] = ($formatBits >> $i) & 1;
        }

        // Around top-left
        $coordsTopLeft = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        for ($i = 0; $i < 15; $i++) {
            $matrix[$coordsTopLeft[$i][0]][$coordsTopLeft[$i][1]] = $bits[$i];
        }

        // Along edges
        // Top right (bits 0..7)
        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 1 - $i] = $bits[$i];
        }
        // Bottom left (bits 8..14)
        for ($i = 0; $i < 7; $i++) {
            $matrix[$size - 7 + $i][8] = $bits[8 + $i];
        }
    }

    private static function applyVersionInfo(&$matrix, $version, $size) {
        if ($version < 7) return;
        $verBits = self::$versionInfo[$version];
        for ($i = 0; $i < 18; $i++) {
            $bit = ($verBits >> $i) & 1;
            $r = (int)($i / 3);
            $c = $i % 3;
            // Bottom-left
            $matrix[$size - 11 + $c][$r] = $bit;
            // Top-right
            $matrix[$r][$size - 11 + $c] = $bit;
        }
    }

    private static function calculatePenalty($matrix, $size) {
        $penalty = 0;

        // Rule 1: 5 or more same color in row/col
        for ($r = 0; $r < $size; $r++) {
            $consec = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($matrix[$r][$c] == $matrix[$r][$c - 1]) {
                    $consec++;
                    if ($consec == 5) $penalty += 3;
                    elseif ($consec > 5) $penalty += 1;
                } else {
                    $consec = 1;
                }
            }
        }
        for ($c = 0; $c < $size; $c++) {
            $consec = 1;
            for ($r = 1; $r < $size; $r++) {
                if ($matrix[$r][$c] == $matrix[$r - 1][$c]) {
                    $consec++;
                    if ($consec == 5) $penalty += 3;
                    elseif ($consec > 5) $penalty += 1;
                } else {
                    $consec = 1;
                }
            }
        }

        // Rule 2: 2x2 blocks of same color
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $val = $matrix[$r][$c];
                if ($val == $matrix[$r + 1][$c] && $val == $matrix[$r][$c + 1] && $val == $matrix[$r + 1][$c + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: 1:1:3:1:1 pattern (finder-like)
        $pattern = [1, 0, 1, 1, 1, 0, 1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 7; $c++) {
                $match = true;
                for ($k = 0; $k < 7; $k++) {
                    if ($matrix[$r][$c + $k] != $pattern[$k]) { $match = false; break; }
                }
                if ($match) {
                    // Check 4 white before or after
                    $leftWhite = ($c >= 4 && $matrix[$r][$c-1] == 0 && $matrix[$r][$c-2] == 0 && $matrix[$r][$c-3] == 0 && $matrix[$r][$c-4] == 0);
                    $rightWhite = ($c + 11 <= $size && $matrix[$r][$c+7] == 0 && $matrix[$r][$c+8] == 0 && $matrix[$r][$c+9] == 0 && $matrix[$r][$c+10] == 0);
                    if ($leftWhite || $rightWhite) $penalty += 40;
                }
            }
        }

        // Rule 4: Proportion of dark modules
        $darkCount = 0;
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] == 1) $darkCount++;
            }
        }
        $total = $size * $size;
        $pct = ($darkCount / $total) * 100;
        $prevMultiple = (int)($pct / 5) * 5;
        $nextMultiple = $prevMultiple + 5;
        $step = (int)(min(abs($prevMultiple - 50), abs($nextMultiple - 50)) / 5);
        $penalty += $step * 10;

        return $penalty;
    }

    /**
     * Generate SVG string (No GD required, razor-sharp vector output)
     */
    public static function svg($text, $filename = false, $level = self::QR_ECLEVEL_M, $pixelSize = 4, $margin = 2) {
        $matrix = self::buildMatrix($text, $level);
        $size = count($matrix);
        $totalSize = ($size + ($margin * 2)) * $pixelSize;

        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $totalSize . '" height="' . $totalSize . '" viewBox="0 0 ' . $totalSize . ' ' . $totalSize . '" shape-rendering="crispEdges">' . "\n";
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>' . "\n";
        $svg .= '<path fill="#000000" d="';

        $d = '';
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] == 1) {
                    $x = ($c + $margin) * $pixelSize;
                    $y = ($r + $margin) * $pixelSize;
                    $d .= "M{$x},{$y}h{$pixelSize}v{$pixelSize}h-{$pixelSize}z ";
                }
            }
        }
        $svg .= trim($d) . '"/>' . "\n";
        $svg .= '</svg>';

        if ($filename) {
            file_put_contents($filename, $svg);
            return $filename;
        }
        return $svg;
    }

    /**
     * Generate PNG file or stream (Standard PHP QR Code interface)
     */
    public static function png($text, $filename = false, $level = self::QR_ECLEVEL_M, $size = 4, $margin = 2, $sendHeader = true) {
        if (!extension_loaded('gd')) {
            // Fallback to SVG if GD is not present
            return self::svg($text, $filename, $level, $size, $margin);
        }

        $matrix = self::buildMatrix($text, $level);
        $matrixSize = count($matrix);
        $imgSize = ($matrixSize + ($margin * 2)) * $size;

        $img = imagecreatetruecolor($imgSize, $imgSize);
        $bg = imagecolorallocate($img, 255, 255, 255);
        $fg = imagecolorallocate($img, 0, 0, 0);

        imagefill($img, 0, 0, $bg);

        for ($r = 0; $r < $matrixSize; $r++) {
            for ($c = 0; $c < $matrixSize; $c++) {
                if ($matrix[$r][$c] == 1) {
                    $x = ($c + $margin) * $size;
                    $y = ($r + $margin) * $size;
                    imagefilledrectangle($img, $x, $y, $x + $size - 1, $y + $size - 1, $fg);
                }
            }
        }

        if ($filename === false) {
            if ($sendHeader && !headers_sent()) {
                header('Content-Type: image/png');
            }
            imagepng($img);
        } else {
            imagepng($img, $filename);
        }
        imagedestroy($img);
        return $filename;
    }

    /**
     * Get Base64 Data URI for direct embedding in <img src="...">
     */
    public static function base64($text, $level = self::QR_ECLEVEL_M, $size = 4, $margin = 2) {
        if (extension_loaded('gd')) {
            ob_start();
            self::png($text, false, $level, $size, $margin, false);
            $raw = ob_get_clean();
            return 'data:image/png;base64,' . base64_encode($raw);
        } else {
            $svg = self::svg($text, false, $level, $size, $margin);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
    }
}

} // end if !class_exists
