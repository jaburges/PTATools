<?php
/**
 * Illustrated campaign meters for [donation-progress style="…"] and the
 * Donation Progress widget.
 *
 * Every shape is positioned server-side from the percent, so the art renders
 * without JavaScript and is safe to page-cache. CSS only adds gentle motion.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Donation_Progress_Art {

    const STYLES = array(
        'bar'         => 'Horizontal bar',
        'bulb'        => 'Bulb grows into a tulip (vertical)',
        'tree'        => 'Acorn grows into an oak tree (vertical)',
        'garden'      => 'Garden bed blooms left to right (horizontal)',
        'thermometer' => 'Thermometer (vertical)',
    );

    const VERTICAL = array('bulb', 'tree', 'thermometer');

    const ALIASES = array(
        'horizontal' => 'bar',
        'default'    => 'bar',
        'flower'     => 'bulb',
        'tulip'      => 'bulb',
        'plant'      => 'bulb',
        'acorn'      => 'tree',
        'oak'        => 'tree',
        'flowers'    => 'garden',
        'flower-bed' => 'garden',
        'flowerbed'  => 'garden',
        'thermo'     => 'thermometer',
    );

    private static $seq = 0;

    public static function normalize_style($style) {
        $key = preg_replace('/[\s_]+/', '-', strtolower(trim((string) $style)));
        if (isset(self::STYLES[$key])) {
            return $key;
        }
        return isset(self::ALIASES[$key]) ? self::ALIASES[$key] : 'bar';
    }

    public static function is_vertical($style) {
        return in_array(self::normalize_style($style), self::VERTICAL, true);
    }

    /** 0 before $from, 1 after $to, linear in between. */
    public static function ramp($pct, $from, $to) {
        if ($to <= $from) {
            return $pct >= $to ? 1.0 : 0.0;
        }
        return max(0.0, min(1.0, ($pct - $from) / ($to - $from)));
    }

    /**
     * @return string SVG markup, or '' for the plain bar.
     */
    public static function render_svg($style, $pct, $goal = 0.0) {
        $pct = max(0, min(100, (int) $pct));
        $id = 'pta-art-' . (++self::$seq);
        switch (self::normalize_style($style)) {
            case 'bulb':
                return self::bulb_svg($pct, $id);
            case 'tree':
                return self::tree_svg($pct, $id);
            case 'garden':
                return self::garden_svg($pct, $id);
            case 'thermometer':
                return self::thermometer_svg($pct, (float) $goal, $id);
        }
        return '';
    }

    // ─── Bulb → tulip ────────────────────────────────────────────────

    private static function bulb_svg($pct, $id) {
        $grow = self::ramp($pct, 4, 85);
        $leaf = self::ramp($pct, 12, 55);
        $bud = self::ramp($pct, 50, 85);
        $open = self::ramp($pct, 85, 100);
        $root = 0.4 + 0.6 * self::ramp($pct, 0, 30);
        $top = 268 - (10 + 172 * $grow);

        $s = self::open_svg('0 0 200 340', $id, '
            <linearGradient id="' . $id . '-soil" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8a5a36"/><stop offset="1" stop-color="#5d3a1f"/></linearGradient>
            <linearGradient id="' . $id . '-bulb" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f3dcb4"/><stop offset="1" stop-color="#b98552"/></linearGradient>
            <linearGradient id="' . $id . '-leaf" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#7cc96f"/><stop offset="1" stop-color="#2f7d32"/></linearGradient>
            <linearGradient id="' . $id . '-petal" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff8fb1"/><stop offset="1" stop-color="#c2185b"/></linearGradient>
            <linearGradient id="' . $id . '-petal2" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffc1d4"/><stop offset="1" stop-color="#ec407a"/></linearGradient>');

        $s .= '<path d="M0,268 Q50,256 100,264 T200,262 V340 H0 Z" fill="url(#' . $id . '-soil)"/>';
        $s .= '<ellipse cx="34" cy="300" rx="5" ry="3" fill="#4e3018"/><ellipse cx="162" cy="292" rx="4" ry="2.5" fill="#4e3018"/><ellipse cx="150" cy="326" rx="6" ry="3" fill="#4e3018"/>';

        $s .= '<g transform="translate(100 316) scale(' . self::n($root) . ')" fill="none" stroke="#ead6ae" stroke-width="1.8" stroke-linecap="round">'
            . '<path d="M0,0 C-4,8 -12,12 -16,22"/><path d="M0,0 C2,10 -2,16 2,26"/><path d="M0,0 C5,7 12,10 16,20"/><path d="M-4,0 C-10,4 -18,4 -24,10"/><path d="M4,0 C10,4 18,4 24,10"/></g>';
        $s .= '<path d="M100,270 C88,280 78,290 80,302 C82,314 92,318 100,318 C108,318 118,314 120,302 C122,290 112,280 100,270 Z" fill="url(#' . $id . '-bulb)" stroke="#a0703f" stroke-width="1.2"/>';
        $s .= '<path d="M100,272 C93,286 91,302 95,316 M100,272 C107,286 109,302 105,316" fill="none" stroke="#a0703f" stroke-width="1" opacity="0.55"/>';

        if ($grow > 0) {
            $h = 268 - $top;
            $s .= '<g class="pta-art-sway">';
            if ($leaf > 0) {
                $s .= '<g transform="translate(98 266) rotate(-4) scale(' . self::n($leaf) . ')"><path d="M0,0 C-28,-18 -38,-72 -24,-132 C-15,-88 -6,-44 3,0 Z" fill="url(#' . $id . '-leaf)"/><path d="M0,-2 C-12,-40 -18,-80 -22,-120" fill="none" stroke="#2e6b2b" stroke-width="1" opacity="0.5"/></g>';
                $s .= '<g transform="translate(102 266) rotate(5) scale(' . self::n(-0.88 * $leaf) . ' ' . self::n(0.88 * $leaf) . ')"><path d="M0,0 C-28,-18 -38,-72 -24,-132 C-15,-88 -6,-44 3,0 Z" fill="url(#' . $id . '-leaf)"/></g>';
            }
            $s .= '<path d="M100,268 C97,' . self::n(268 - $h * 0.35) . ' 103,' . self::n(268 - $h * 0.7) . ' 100,' . self::n($top)
                . '" fill="none" stroke="#3f8f3a" stroke-width="' . self::n(3 + 2.5 * $grow) . '" stroke-linecap="round"/>';
            if ($bud > 0) {
                $spread = 6 + 26 * $open;
                $petal = 'M0,0 C-17,-4 -19,-36 0,-56 C19,-36 17,-4 0,0 Z';
                $s .= '<g class="pta-art-bloom" transform="translate(100 ' . self::n($top + 2) . ') scale(' . self::n(0.3 + 0.7 * $bud) . ')">'
                    . '<path d="' . $petal . '" fill="url(#' . $id . '-petal)"/>'
                    . '<path d="' . $petal . '" transform="rotate(' . self::n(-$spread) . ') translate(-3 0)" fill="url(#' . $id . '-petal)"/>'
                    . '<path d="' . $petal . '" transform="rotate(' . self::n($spread) . ') translate(3 0)" fill="url(#' . $id . '-petal)"/>'
                    . '<path d="M0,2 C-15,-2 -16,-30 0,-48 C16,-30 15,-2 0,2 Z" fill="url(#' . $id . '-petal2)"/>';
                if ($bud < 1) {
                    $s .= '<path d="M0,2 C-18,-2 -20,-34 0,-54 C20,-34 18,-2 0,2 Z" fill="#5aa64f" opacity="' . self::n(0.9 * (1 - $bud)) . '"/>';
                }
                $s .= '</g>';
            }
            $s .= '</g>';
        }

        if ($pct >= 100) {
            $s .= self::butterfly(152, 74);
            $s .= self::sparkles(array(array(56, 60, 7), array(146, 40, 6), array(64, 120, 5), array(140, 130, 6)));
        }
        return $s . '</svg>';
    }

    // ─── Acorn → oak ─────────────────────────────────────────────────

    private static function tree_svg($pct, $id) {
        $g = self::ramp($pct, 8, 100);
        $wood = self::ramp($pct, 25, 50);
        $ht = 6 + 164 * pow($g, 0.9);
        $wb = 3 + 21 * self::ramp($pct, 25, 100);
        $wt = max(2.0, $wb * 0.45);
        $gx = 110;
        $gy = 280;
        $trunk = self::mix('#5cae4f', '#7b5132', $wood);

        $s = self::open_svg('0 0 220 340', $id, '
            <linearGradient id="' . $id . '-hill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8bc34a"/><stop offset="1" stop-color="#558b2f"/></linearGradient>
            <linearGradient id="' . $id . '-nut" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#d4975a"/><stop offset="1" stop-color="#8d5524"/></linearGradient>');

        $s .= '<path d="M0,290 Q110,266 220,290 V340 H0 Z" fill="url(#' . $id . '-hill)"/>';
        $s .= '<path d="M0,314 Q110,302 220,314 V340 H0 Z" fill="#7a4b2a"/>';

        $shell = 1 - self::ramp($pct, 30, 45);
        if ($shell > 0) {
            if ($pct < 8) {
                $s .= '<g transform="translate(' . $gx . ' 262) rotate(8)">' . self::acorn_nut($id, $pct >= 3) . self::acorn_cap() . '</g>';
            } else {
                $s .= '<g opacity="' . self::n($shell) . '"><g transform="translate(88 276) rotate(-38)">' . self::acorn_nut($id, true) . '</g>'
                    . '<g transform="translate(134 278) rotate(28)">' . self::acorn_cap() . '</g></g>';
            }
        }

        if ($g > 0) {
            $tx = $gy - $ht;
            $s .= '<g class="pta-art-sway">';
            $branch = self::ramp($pct, 45, 80);
            if ($branch > 0) {
                $bw = self::n(max(2.0, $wb * 0.3));
                $y1 = $gy - $ht * 0.55;
                $y2 = $gy - $ht * 0.72;
                $s .= '<path d="M' . $gx . ',' . self::n($y1) . ' Q' . self::n($gx - 20 * $branch) . ',' . self::n($y1 - 8 * $branch) . ' ' . self::n($gx - 40 * $branch) . ',' . self::n($y1 - 34 * $branch)
                    . ' M' . $gx . ',' . self::n($y2) . ' Q' . self::n($gx + 20 * $branch) . ',' . self::n($y2 - 8 * $branch) . ' ' . self::n($gx + 38 * $branch) . ',' . self::n($y2 - 30 * $branch)
                    . '" fill="none" stroke="' . $trunk . '" stroke-width="' . $bw . '" stroke-linecap="round"/>';
            }
            $s .= '<path d="M' . self::n($gx - $wb / 2) . ',' . ($gy + 2) . ' Q' . self::n($gx - $wb * 0.3) . ',' . self::n($gy - $ht * 0.3) . ' ' . self::n($gx - $wt / 2) . ',' . self::n($tx)
                . ' L' . self::n($gx + $wt / 2) . ',' . self::n($tx) . ' Q' . self::n($gx + $wb * 0.3) . ',' . self::n($gy - $ht * 0.3) . ' ' . self::n($gx + $wb / 2) . ',' . ($gy + 2) . ' Z" fill="' . $trunk . '"/>';

            $seedling = self::ramp($pct, 10, 22) * (1 - self::ramp($pct, 40, 52));
            if ($seedling > 0) {
                $s .= '<g transform="translate(' . $gx . ' ' . self::n($tx) . ') scale(' . self::n(self::ramp($pct, 10, 22)) . ')" opacity="' . self::n(1 - self::ramp($pct, 40, 52)) . '">'
                    . '<ellipse cx="-10" cy="-2" rx="11" ry="5.5" transform="rotate(-28)" fill="#66bb6a"/><ellipse cx="10" cy="-2" rx="11" ry="5.5" transform="rotate(28)" fill="#43a047"/></g>';
            }

            $canopy = self::ramp($pct, 35, 95);
            if ($canopy > 0) {
                $blobs = array(
                    array(-52, -14, 24, '#2e7d32'), array(52, -14, 24, '#2e7d32'),
                    array(-40, 10, 34, '#388e3c'), array(40, 10, 34, '#388e3c'),
                    array(-26, -36, 32, '#43a047'), array(26, -36, 32, '#43a047'),
                    array(0, -52, 30, '#4caf50'), array(0, -10, 48, '#43a047'),
                    array(-18, -30, 12, '#66bb6a'), array(20, -48, 10, '#66bb6a'), array(30, -6, 9, '#66bb6a'),
                );
                $s .= '<g transform="translate(' . $gx . ' ' . self::n($tx - 6) . ') scale(' . self::n(0.12 + 0.88 * $canopy) . ')">';
                foreach ($blobs as $b) {
                    $s .= '<circle cx="' . $b[0] . '" cy="' . $b[1] . '" r="' . $b[2] . '" fill="' . $b[3] . '"/>';
                }
                if ($pct >= 100) {
                    foreach (array(array(-30, 0), array(22, -20), array(38, 18), array(-12, -42), array(4, 16), array(-44, -20)) as $a) {
                        $s .= '<g transform="translate(' . $a[0] . ' ' . $a[1] . ')"><ellipse cx="0" cy="2" rx="3.4" ry="4.4" fill="#b06a32"/><path d="M-4,0 C-4,-3 -2,-4 0,-4 C2,-4 4,-3 4,0 Z" fill="#6d4c2f"/></g>';
                    }
                }
                $s .= '</g>';
            }
            $s .= '</g>';
        }

        if ($pct >= 100) {
            $s .= self::sparkles(array(array(30, 70, 7), array(192, 52, 6), array(24, 150, 5), array(198, 140, 6)));
        }
        return $s . '</svg>';
    }

    private static function acorn_nut($id, $cracked) {
        $out = '<path d="M-11,-4 C-11,8 -5,16 0,18 C5,16 11,8 11,-4 Z" fill="url(#' . $id . '-nut)"/>';
        if ($cracked) {
            $out .= '<path d="M-2,-3 L1,3 L-2,8 L1,13" fill="none" stroke="#5d3a1a" stroke-width="1.2"/>';
        }
        return $out;
    }

    private static function acorn_cap() {
        return '<path d="M-13,-3 C-13,-12 -7,-16 0,-16 C7,-16 13,-12 13,-3 Z" fill="#6d4c2f"/>'
            . '<path d="M-9,-8 h18 M-11,-4 h22 M-6,-12 h12" stroke="#8a6440" stroke-width="1" stroke-dasharray="2 2"/>'
            . '<path d="M0,-16 q1,-5 4,-7" fill="none" stroke="#6d4c2f" stroke-width="2.5" stroke-linecap="round"/>';
    }

    // ─── Garden bed ──────────────────────────────────────────────────

    private static function garden_svg($pct, $id) {
        $s = self::open_svg('0 0 520 160', $id, '
            <linearGradient id="' . $id . '-soil" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8a5a36"/><stop offset="1" stop-color="#5d3a1f"/></linearGradient>
            <linearGradient id="' . $id . '-grass" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#9ccc65"/><stop offset="1" stop-color="#558b2f"/></linearGradient>');

        $s .= '<rect x="8" y="122" width="504" height="30" rx="15" fill="url(#' . $id . '-soil)"/>';
        $w = 504 * $pct / 100;
        if ($w > 0) {
            $s .= '<rect x="8" y="116" width="' . self::n(max(18.0, $w)) . '" height="16" rx="8" fill="url(#' . $id . '-grass)"/>';
        }

        $count = 6;
        for ($i = 0; $i < $count; $i++) {
            $at = ($i + 0.5) / $count * 100;
            $x = 8 + 504 * $at / 100;
            $stage = $pct > 0 ? self::ramp($pct, $at - 16, $at) : 0.0;
            if ($stage <= 0) {
                $s .= '<ellipse cx="' . self::n($x) . '" cy="123" rx="10" ry="4.5" fill="#4e3018"/>';
                continue;
            }
            $stem = 12 + 50 * $stage;
            $top = 120 - $stem;
            $leaf = self::n(min(1.0, $stage * 1.6));
            $s .= '<g class="pta-art-sway" style="animation-delay:-' . self::n($i * 0.7) . 's">';
            $s .= '<path d="M' . self::n($x) . ',122 V' . self::n($top) . '" stroke="#3f8f3a" stroke-width="3" stroke-linecap="round"/>';
            $s .= '<g transform="translate(' . self::n($x) . ' ' . self::n(120 - $stem * 0.35) . ') scale(' . $leaf . ')">'
                . '<path d="M0,0 C-6,-6 -16,-6 -20,-2 C-14,2 -6,3 0,0 Z" fill="#4caf50"/><path d="M0,-4 C6,-10 16,-10 20,-6 C14,-2 6,-1 0,-4 Z" fill="#388e3c"/></g>';
            if ($stage >= 1) {
                $s .= '<g class="pta-art-bloom" transform="translate(' . self::n($x) . ' ' . self::n($top) . ') scale(1.35)">' . self::garden_flower($i % 4) . '</g>';
            } elseif ($stage > 0.55) {
                $s .= '<g transform="translate(' . self::n($x) . ' ' . self::n($top) . ') scale(' . self::n(($stage - 0.55) / 0.45) . ')"><ellipse cx="0" cy="-7" rx="5" ry="8" fill="' . self::garden_bud_color($i % 4) . '"/><path d="M-5,-2 Q0,-10 5,-2 Z" fill="#43a047"/></g>';
            }
            $s .= '</g>';
        }

        if ($pct >= 100) {
            $s .= self::sparkles(array(array(26, 24, 7), array(176, 12, 6), array(344, 20, 7), array(496, 14, 6)));
        }
        return $s . '</svg>';
    }

    private static function garden_bud_color($type) {
        $colors = array('#ec407a', '#f5f5f5', '#fdd835', '#8e24aa');
        return $colors[$type];
    }

    private static function garden_flower($type) {
        if ($type === 0 || $type === 3) {
            $fill = $type === 0 ? '#ec407a' : '#8e24aa';
            $front = $type === 0 ? '#f48fb1' : '#ba68c8';
            $petal = 'M0,0 C-10,-2 -11,-20 0,-30 C11,-20 10,-2 0,0 Z';
            return '<path d="' . $petal . '" transform="rotate(-20) translate(-2 0)" fill="' . $fill . '"/><path d="' . $petal . '" transform="rotate(20) translate(2 0)" fill="' . $fill . '"/>'
                . '<path d="M0,1 C-9,-1 -10,-17 0,-26 C10,-17 9,-1 0,1 Z" fill="' . $front . '"/>';
        }
        $petals = $type === 1 ? 10 : 14;
        $out = '<g transform="translate(0 -14)">';
        for ($k = 0; $k < $petals; $k++) {
            $out .= $type === 1
                ? '<ellipse cx="0" cy="-9" rx="3.6" ry="8" transform="rotate(' . ($k * 36) . ')" fill="#ffffff" stroke="#e0e0e0" stroke-width="0.8"/>'
                : '<ellipse cx="0" cy="-11" rx="3.8" ry="8.5" transform="rotate(' . self::n($k * 360 / $petals) . ')" fill="#fdd835" stroke="#f9a825" stroke-width="0.8"/>';
        }
        $out .= $type === 1
            ? '<circle r="5" fill="#fbc02d"/>'
            : '<circle r="7.5" fill="#6d4c41"/><circle cx="-2" cy="-2" r="1.2" fill="#4e342e"/><circle cx="2.5" cy="1" r="1.2" fill="#4e342e"/><circle cx="-1" cy="3" r="1.2" fill="#4e342e"/>';
        return $out . '</g>';
    }

    // ─── Thermometer ─────────────────────────────────────────────────

    private static function thermometer_svg($pct, $goal, $id) {
        $bottom = 290;
        $span = 258;
        $top = $bottom - $span * $pct / 100;

        $s = self::open_svg('0 0 190 340', $id, '
            <linearGradient id="' . $id . '-mercury" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ff7043"/><stop offset="1" stop-color="#d32f2f"/></linearGradient>');

        $s .= '<rect x="48" y="16" width="44" height="280" rx="22" fill="#ffffff" stroke="currentColor" stroke-width="4"/>';
        $s .= '<circle cx="70" cy="296" r="34" fill="#ffffff" stroke="currentColor" stroke-width="4"/>';
        $s .= '<rect x="50" y="250" width="40" height="50" fill="#ffffff"/>';
        $s .= '<circle cx="70" cy="296" r="26" fill="url(#' . $id . '-mercury)"/>';
        if ($pct > 0) {
            $s .= '<rect x="58" y="' . self::n($top) . '" width="24" height="' . self::n($bottom + 8 - $top) . '" rx="12" fill="url(#' . $id . '-mercury)"/>';
            $s .= '<rect x="63" y="' . self::n($top + 6) . '" width="5" height="' . self::n(max(0.0, $bottom - $top - 6)) . '" rx="2.5" fill="#ffffff" opacity="0.35"/>';
        }

        for ($k = 0; $k <= 20; $k++) {
            $y = self::n($bottom - $span * $k / 20);
            $major = $k % 5 === 0;
            $s .= '<path d="M96,' . $y . ' h' . ($major ? 14 : 7) . '" stroke="currentColor" stroke-width="' . ($major ? 2 : 1) . '" opacity="' . ($major ? 1 : 0.55) . '"/>';
            if ($major) {
                $label = $goal > 0 ? '$' . number_format($goal * $k / 20) : ($k * 5) . '%';
                $s .= '<text x="116" y="' . $y . '" dominant-baseline="middle" font-size="13" font-weight="700" fill="currentColor">' . esc_html($label) . '</text>';
            }
        }

        if ($pct >= 100) {
            $s .= self::sparkles(array(array(30, 30, 7), array(110, 8, 6), array(26, 90, 5)));
        }
        return $s . '</svg>';
    }

    // ─── Shared pieces ───────────────────────────────────────────────

    private static function open_svg($view_box, $id, $defs) {
        return '<svg class="pta-art-svg" viewBox="' . $view_box . '" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><defs>' . trim($defs) . '</defs>';
    }

    private static function sparkles(array $points) {
        $out = '<g class="pta-art-sparkles" fill="#f6c343">';
        foreach ($points as $i => $p) {
            list($x, $y, $r) = $p;
            $out .= '<path class="pta-art-twinkle" style="animation-delay:' . ($i * 0.35) . 's" d="M' . $x . ',' . ($y - $r) . ' Q' . $x . ',' . $y . ' ' . ($x + $r) . ',' . $y
                . ' Q' . $x . ',' . $y . ' ' . $x . ',' . ($y + $r) . ' Q' . $x . ',' . $y . ' ' . ($x - $r) . ',' . $y . ' Q' . $x . ',' . $y . ' ' . $x . ',' . ($y - $r) . ' Z"/>';
        }
        return $out . '</g>';
    }

    private static function butterfly($x, $y) {
        $wing = 'M0,0 C-14,-16 -26,-6 -18,4 C-12,10 -4,6 0,0 Z';
        $low = 'M0,1 C-10,4 -14,14 -6,15 C-2,15 0,8 0,1 Z';
        return '<g transform="translate(' . $x . ' ' . $y . ') rotate(-15)"><g class="pta-art-flutter">'
            . '<path d="' . $wing . '" fill="#ffb74d"/><path d="' . $wing . '" transform="scale(-1 1)" fill="#ffb74d"/>'
            . '<path d="' . $low . '" fill="#fb8c00"/><path d="' . $low . '" transform="scale(-1 1)" fill="#fb8c00"/>'
            . '</g><ellipse rx="1.8" ry="8" cy="3" fill="#5d4037"/><path d="M-1,-4 q-3,-6 -6,-7 M1,-4 q3,-6 6,-7" fill="none" stroke="#5d4037" stroke-width="1"/></g>';
    }

    private static function mix($from, $to, $t) {
        $a = sscanf($from, '#%02x%02x%02x');
        $b = sscanf($to, '#%02x%02x%02x');
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round($a[$i] + ($b[$i] - $a[$i]) * $t));
        }
        return $out;
    }

    private static function n($v) {
        $s = rtrim(rtrim(sprintf('%.2f', (float) $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
