<?php
/**
 * Banco de pruebas de la Fase 3: generador HTML → WhatsApp y tokens.
 * Incluye una pasada sobre las descripciones REALES de descripcion-html/.
 */

define('ABSPATH', true);
define('VCV_URL', '');
define('VCV_VERSION', '0.3.0');

// ── Stubs de WordPress ───────────────────────────────────────────────────
function is_wp_error($t) { return false; }
function taxonomy_exists($t) { return in_array($t, ['pa_sucursal', 'pa_color'], true); }
function get_terms($args) {
    if ($args['taxonomy'] === 'pa_sucursal') {
        return [
            (object) ['slug' => 'sucursal-cbba-stock', 'name' => 'Cochabamba'],
            (object) ['slug' => 'sucursal-scz-stock',  'name' => 'Santa Cruz'],
        ];
    }
    return [];
}
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_html__($s, $d = null) { return esc_html($s); }
function esc_textarea($s) { return esc_html($s); }
function strip_shortcodes($s) { return preg_replace('/\[[^\]]*\]/', '', (string) $s); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wc_price($n) { return '<span class="amount"><bdi>Bs&nbsp;' . number_format((float) $n, 2) . '</bdi></span>'; }
function get_user_meta($id, $k, $single = false) { return ''; }
function get_userdata($id) { return null; }
function wp_get_current_user() { return (object) ['ID' => 0, 'roles' => []]; }

// Almacén falso de posts y meta, para la cascada manual → auto → fallback.
$GLOBALS['_posts'] = [];
$GLOBALS['_meta']  = [];
$GLOBALS['_writes'] = 0;
function get_post($id) {
    return isset($GLOBALS['_posts'][$id]) ? (object) $GLOBALS['_posts'][$id] : null;
}
function get_post_meta($id, $key, $single = false) {
    return isset($GLOBALS['_meta'][$id][$key]) ? $GLOBALS['_meta'][$id][$key] : '';
}
function update_post_meta($id, $key, $value) {
    $GLOBALS['_meta'][$id][$key] = $value;
    $GLOBALS['_writes']++;
    return true;
}

$base = 'F:/VENTOVA/produccion/complementos/ventova-catalogo-vendedor/includes/';
require_once $base . 'class-vcv-sucursales.php';
require_once $base . 'class-vcv-permisos.php';
require_once $base . 'class-vcv-resumen.php';

// ── Utilidades ───────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check($label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  OK   $label\n"; return; }
    $fail++;
    echo "  FAIL $label\n       esperado: " . var_export($expected, true)
       . "\n       obtenido: " . var_export($actual, true) . "\n";
}
function check_contains($label, $hay, $needle) {
    global $pass, $fail;
    if (strpos($hay, $needle) !== false) { $pass++; echo "  OK   $label\n"; return; }
    $fail++;
    echo "  FAIL $label — no contiene: " . var_export($needle, true) . "\n";
}
function check_not_contains($label, $hay, $needle) {
    global $pass, $fail;
    if (strpos($hay, $needle) === false) { $pass++; echo "  OK   $label\n"; return; }
    $fail++;
    echo "  FAIL $label — no debia contener: " . var_export($needle, true) . "\n";
}

echo "\n=== 0. Entorno ===\n";
check('DOMDocument disponible', class_exists('DOMDocument'), true);

echo "\n=== 1. Generador HTML -> WhatsApp ===\n";
$html = '<div style="padding:1rem">'
      . '<span style="text-transform:uppercase">CÁMARA INCORPORADA • VENTOVA STORE</span>'
      . '<h2 style="font-size:1.6rem">Smartwatch T83 Pro &ndash; Estilo en tu Muñeca</h2>'
      . '<p>Combina conectividad avanzada &ndash; estilo moderno &amp; útil.</p>'
      . '<h3>Características Principales</h3>'
      . '<div><h4><span style="color:#1a3c8f">✓</span> Cámara Incorporada</h4>'
      . '<p>Captura imágenes desde tu muñeca.</p></div>'
      . '<ul><li>Resistente al agua</li><li>Batería de 7 días</li></ul>'
      . '<script>var x = 1;</script><style>.a{color:red}</style>'
      . '</div>';

$out = VCV_Resumen::generar_desde_html($html);

check_contains('antepone el token del nombre', $out, '*{nombre}*');
check_not_contains('descarta el titulo inicial redundante', $out, 'Estilo en tu Muñeca');
check_contains('h3 -> negrita WhatsApp', $out, '*Características Principales*');
check_contains('h4 -> viñeta', $out, '• Cámara Incorporada');
check_not_contains('quita el check decorativo', $out, '✓');
check_contains('li -> viñeta', $out, '• Resistente al agua');
check_contains('parrafo conservado', $out, 'Combina conectividad avanzada');
check_not_contains('descarta script', $out, 'var x');
check_not_contains('descarta style', $out, 'color:red');
check_not_contains('sin etiquetas HTML', $out, '<');
check_contains('decodifica entidad ndash', $out, 'avanzada – estilo');
check_contains('decodifica entidad amp', $out, 'moderno & útil');
check_not_contains('no deja entidades crudas', $out, '&ndash;');
check_contains('descarta el rotulo decorativo en mayusculas', // "CÁMARA INCORPORADA • VENTOVA STORE"
    (strpos($out, 'VENTOVA STORE') === false) ? 'si' : 'no', 'si');
check_contains('cierra con token de precio', $out, '💵 {precio}');
check_contains('cierra con token de enlace', $out, '🔗 {enlace}');
check_not_contains('sin tres saltos seguidos', $out, "\n\n\n");

// Tabla de especificaciones: <tr><th>etiqueta</th><td>valor</td></tr>
$tabla = '<p>Intro real que no es cabecera decorativa ni titulo.</p>'
       . '<table><tbody>'
       . '<tr><th>Modelo</th><td>T83 Pro</td></tr>'
       . '<tr><th>Batería</th><td>380 mAh</td></tr>'
       . '<tr><td>Una sola celda</td></tr>'
       . '<tr><td>A</td><td>B</td><td>C</td></tr>'
       . '</tbody></table>';
$ot = VCV_Resumen::generar_desde_html($tabla);
check_contains('fila de tabla -> "Etiqueta: valor"', $ot, 'Modelo: T83 Pro');
check_contains('segunda fila', $ot, 'Batería: 380 mAh');
check_not_contains('no parte la etiqueta en su propia linea', $ot, "Modelo\n");
check_contains('fila de una celda se conserva', $ot, 'Una sola celda');
check_contains('fila de 3+ celdas se une con punto medio', $ot, 'A · B · C');

check('descripcion vacia -> cadena vacia', VCV_Resumen::generar_desde_html(''), '');
check('solo shortcodes -> cadena vacia', VCV_Resumen::generar_desde_html('[woo_x id="3"]'), '');

echo "\n=== 2. Tokens (resolve) ===\n";
$ctx = [
    'nombre'   => 'Smartwatch T83',
    'precio'   => 'Bs 250,00',
    'enlace'   => 'https://ventova.test/t83',
    'sku'      => 'T83-001',
    'sucursal' => 'SANTA CRUZ',
    'stock'    => 'SANTA CRUZ (5)',
];

$r = VCV_Resumen::resolve("*{nombre}*\n💵 {precio}\n🔗 {enlace}", $ctx);
check('sustituye nombre/precio/enlace', $r, "*Smartwatch T83*\n💵 Bs 250,00\n🔗 https://ventova.test/t83");

check('plantilla vacia usa el fallback',
    VCV_Resumen::resolve('', $ctx),
    "*Smartwatch T83*\n\n💵 Bs 250,00\n🔗 https://ventova.test/t83");

check_not_contains('elimina tokens desconocidos',
    VCV_Resumen::resolve("{nombre}\n{inventado}", $ctx), '{inventado}');

// Token vacío: la línea entera desaparece, no queda la etiqueta huérfana.
$ctx_sin_suc = array_merge($ctx, ['sucursal' => '']);
$r = VCV_Resumen::resolve("{nombre}\n📍 Disponible en: {sucursal}\n{enlace}", $ctx_sin_suc);
check_not_contains('linea huerfana eliminada', $r, 'Disponible en:');
check_contains('el resto sobrevive', $r, 'https://ventova.test/t83');

// Con valor, la misma línea sí se conserva.
$r = VCV_Resumen::resolve("{nombre}\n📍 Disponible en: {sucursal}", $ctx);
check_contains('con valor la linea se conserva', $r, 'Disponible en: SANTA CRUZ');

echo "\n=== 3. Precio y stock en texto plano ===\n";
check('precio unico sin HTML', VCV_Resumen::precio_plano(250.0), 'Bs 250.00');
check('precio en rango', VCV_Resumen::precio_plano(250.0, 310.0), 'Bs 250.00 – Bs 310.00');
check('min = max no genera rango', VCV_Resumen::precio_plano(250.0, 250.0), 'Bs 250.00');
check('sin precio', VCV_Resumen::precio_plano(null), '');
check_not_contains('precio sin nbsp', VCV_Resumen::precio_plano(250.0), "\xC2\xA0");

$lineas = [
    ['sucursal_name' => 'SANTA CRUZ', 'qty' => 3],
    ['sucursal_name' => 'SANTA CRUZ', 'qty' => 2],
    ['sucursal_name' => 'COCHABAMBA', 'qty' => 4],
];
check('agrega colores de una misma sucursal',
    VCV_Resumen::stock_plano($lineas), 'SANTA CRUZ (5) · COCHABAMBA (4)');
check('ignora lineas sin sucursal',
    VCV_Resumen::stock_plano([['sucursal_name' => '', 'qty' => 9]]), '');

echo "\n=== 4. Texto listo para copiar (texto_para_fila) ===\n";
$row = [
    'title' => 'Smartwatch T83', 'sku' => 'T83-001',
    'permalink' => 'https://ventova.test/t83',
    'price_min' => 250.0, 'price_max' => 250.0,
    'resumen' => "*{nombre}*\nSKU: {sku}\n💵 {precio}",
    'lines' => [['sucursal_name' => 'SANTA CRUZ', 'qty' => 5]],
];
check('usa el resumen redactado',
    VCV_Resumen::texto_para_fila($row),
    "*Smartwatch T83*\nSKU: T83-001\n💵 Bs 250.00");

$row_sin = array_merge($row, ['resumen' => '']);
$t = VCV_Resumen::texto_para_fila($row_sin);
check_contains('sin resumen: nombre', $t, 'Smartwatch T83');
check_contains('sin resumen: precio', $t, 'Bs 250.00');
check_contains('sin resumen: enlace', $t, 'https://ventova.test/t83');

$row_suc = array_merge($row, ['resumen' => 'Entrego en {sucursal}']);
check('resuelve la sucursal de quien copia',
    VCV_Resumen::texto_para_fila($row_suc, 'sucursal-scz-stock'), 'Entrego en SANTA CRUZ');

echo "\n=== 5. Cascada manual -> auto -> fallback ===\n";
// Este es el caso que falló en la prueba real: producto sin resumen redactado
// pero CON descripción. Antes copiaba solo nombre, precio y enlace.
$GLOBALS['_posts'][10] = [
    'post_content' => '<h3>Características</h3><ul><li>WiFi integrado</li><li>Batería 8 h</li></ul>',
    'post_excerpt' => '',
];
$GLOBALS['_posts'][11] = ['post_content' => '', 'post_excerpt' => ''];
$GLOBALS['_posts'][12] = [
    'post_content' => '',
    'post_excerpt' => '<p>Grabadora corporal portátil con visión nocturna.</p>',
];

$fila = function ($id) {
    return [
        'id' => $id, 'title' => 'S70 WIFI', 'sku' => 'S70',
        'permalink' => 'https://ventova.test/s70',
        'price_min' => 460.0, 'price_max' => 460.0,
        'resumen' => '', 'resumen_auto' => '', 'resumen_hash' => '',
        'lines' => [],
    ];
};

$p = VCV_Resumen::plantilla_para_fila($fila(10));
check('con descripcion y sin resumen -> origen auto', $p['origen'], VCV_Resumen::ORIGEN_AUTO);
$t = VCV_Resumen::texto_para_fila($fila(10));
check_contains('el texto copiado trae las caracteristicas', $t, 'WiFi integrado');
check_contains('mantiene el precio', $t, 'Bs 460.00');
check_contains('mantiene el enlace', $t, 'https://ventova.test/s70');

check('sin descripcion -> origen fallback',
    VCV_Resumen::plantilla_para_fila($fila(11))['origen'], VCV_Resumen::ORIGEN_FALLBACK);

check('cae a la descripcion corta',
    VCV_Resumen::plantilla_para_fila($fila(12))['origen'], VCV_Resumen::ORIGEN_AUTO);
check_contains('usa el texto de la descripcion corta',
    VCV_Resumen::texto_para_fila($fila(12)), 'visión nocturna');

// El resumen redactado a mano manda sobre el derivado.
$manual = array_merge($fila(10), ['resumen' => '*{nombre}* a mano']);
$p = VCV_Resumen::plantilla_para_fila($manual);
check('el manual gana al derivado', $p['origen'], VCV_Resumen::ORIGEN_MANUAL);
check('y es el que se copia', VCV_Resumen::texto_para_fila($manual), '*S70 WIFI* a mano');

echo "\n=== 6. Cache del borrador derivado ===\n";
check('se guardo el borrador', trim((string) get_post_meta(10, VCV_Resumen::META_AUTO)) !== '', true);
check('se guardo el hash del HTML',
    get_post_meta(10, VCV_Resumen::META_HASH),
    md5($GLOBALS['_posts'][10]['post_content']));

// Con la meta ya en la fila no debe volver a escribir: es lo que evita que el
// catálogo derive 50 descripciones en cada carga.
$cacheada = array_merge($fila(10), [
    'resumen_auto' => get_post_meta(10, VCV_Resumen::META_AUTO),
    'resumen_hash' => get_post_meta(10, VCV_Resumen::META_HASH),
]);
$antes = $GLOBALS['_writes'];
$p = VCV_Resumen::plantilla_para_fila($cacheada);
check('reusa la cache sin escribir', $GLOBALS['_writes'], $antes);
check('y sigue siendo auto', $p['origen'], VCV_Resumen::ORIGEN_AUTO);

// Descripción editada -> hash distinto -> se rederiva.
$GLOBALS['_posts'][10]['post_content'] = '<ul><li>Ahora con GPS</li></ul>';
$p = VCV_Resumen::plantilla_para_fila($cacheada);
check_contains('rederiva cuando cambia la descripcion', $p['texto'], 'Ahora con GPS');
check('actualiza el hash guardado',
    get_post_meta(10, VCV_Resumen::META_HASH),
    md5($GLOBALS['_posts'][10]['post_content']));

echo "\n=== 7. Descripciones REALES de descripcion-html/ ===\n";
$dir = 'F:/VENTOVA/produccion/descripcion-html/';
foreach (glob($dir . '*.html') as $archivo) {
    $nombre = basename($archivo);
    $texto  = VCV_Resumen::generar_desde_html(file_get_contents($archivo));

    $sin_tags   = (strpos($texto, '<') === false);
    $sin_estilo = (stripos($texto, 'font-family') === false && stripos($texto, 'rgba(') === false);
    $tiene_vin  = (strpos($texto, '• ') !== false);
    $tiene_neg  = (strpos($texto, '*') !== false);
    $razonable  = (strlen($texto) > 200);

    $ok = $sin_tags && $sin_estilo && $tiene_vin && $tiene_neg && $razonable;
    if ($ok) { $pass++; printf("  OK   %-28s %5d chars\n", $nombre, strlen($texto)); }
    else {
        $fail++;
        printf("  FAIL %-28s tags:%d estilo:%d viñetas:%d negrita:%d largo:%d\n",
            $nombre, $sin_tags, $sin_estilo, $tiene_vin, $tiene_neg, $razonable);
    }
}

echo "\n── Muestra generada (smartwatch-t83.html) ──────────────────────\n";
echo VCV_Resumen::generar_desde_html(file_get_contents($dir . 'smartwatch-t83.html'));
echo "\n────────────────────────────────────────────────────────────────\n";

echo "\nPASS: $pass   FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
